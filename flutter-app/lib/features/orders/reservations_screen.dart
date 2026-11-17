import 'package:flutter/material.dart' hide Text;

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../ui/widgets.dart';
import '../../ui/tr_text.dart';
import '../../core/l10n.dart';

const _reservationActions = {
  'booked': ['seated', 'no_show', 'cancelled'],
  'seated': ['completed'],
};

/// Table bookings for today and the coming days: list, take a new booking
/// and move it through its statuses, like the web reservations page.
class ReservationsScreen extends StatefulWidget {
  const ReservationsScreen({super.key});

  @override
  State<ReservationsScreen> createState() => _ReservationsScreenState();
}

class _ReservationsScreenState extends State<ReservationsScreen> {
  final _key = GlobalKey<DataViewState<List<Map<String, dynamic>>>>();

  Future<void> _setStatus(Map<String, dynamic> r, String status) async {
    final app = AppScope.read(context);
    // The mounted check above covers this context use after the sheet.
    // ignore: use_build_context_synchronously
    final ok = await guarded(context, () async {
      await app.api.patch('/branches/${app.branchId}/reservations/${r['id']}/status',
          body: {'status': status});
    });
    if (ok) {
      _key.currentState?.reload();
    }
  }

  Future<List<(String, String)>> _methods() async {
    final app = AppScope.read(context);
    try {
      final res = await app.cachedGet('/branches/${app.branchId}/reference');
      final list = ((res.data as Map)['payment_methods'] as List?)?.whereType<Map>().toList() ?? const [];
      return [for (final m in list) ('${m['method']}', localized(app, m))];
    } on ApiException {
      return const [('cash', 'Cash')];
    }
  }

  /// Booking money: collect a deposit on a booking that holds none.
  Future<void> _takeDeposit(Map<String, dynamic> r) async {
    final app = AppScope.read(context);
    final amount = TextEditingController();
    final reference = TextEditingController();
    var method = 'cash';
    final methods = await _methods();
    if (!mounted) {
      amount.dispose();
      reference.dispose();
      return;
    }
    final go = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => SafeArea(
          child: Padding(
            padding: EdgeInsets.fromLTRB(
                20, 0, 20, MediaQuery.of(ctx).viewInsets.bottom + MediaQuery.of(ctx).padding.bottom + 20),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
              Text(tr('Booking money'), style: Theme.of(ctx).textTheme.titleLarge),
              const SizedBox(height: 12),
              field(amount, tr('Amount'), type: const TextInputType.numberWithOptions(decimal: true)),
              const SizedBox(height: 12),
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final (code, name) in methods)
                  FilterChip(
                    label: Text(name),
                    selected: method == code,
                    onSelected: (on) => setSheet(() => method = code),
                  ),
              ]),
              const SizedBox(height: 12),
              field(reference, '${tr('Reference')} (${tr('optional')})'),
              FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                child: Text(tr('Collect')),
              ),
            ]),
          ),
        ),
      ),
    );
    final value = double.tryParse(amount.text.trim());
    final refText = reference.text.trim();
    amount.dispose();
    reference.dispose();
    if (go != true || value == null || value <= 0 || !mounted) {
      return;
    }
    final ok = await guarded(context, () async {
      await app.api.post('/branches/${app.branchId}/reservations/${r['id']}/deposit', body: {
        'deposit_amount': value,
        'deposit_method': method,
        if (refText.isNotEmpty) 'deposit_reference': refText,
      });
    });
    if (ok) {
      _key.currentState?.reload();
    }
  }

  /// Give the booking money back, or keep it after a no-show or a late cancel.
  Future<void> _closeDeposit(Map<String, dynamic> r, String outcome) async {
    final app = AppScope.read(context);
    final go = await confirm(
      context,
      outcome == 'refund' ? tr('Refund the booking money?') : tr('Keep the booking money?'),
      action: outcome == 'refund' ? tr('Refund') : tr('Keep'),
    );
    if (go != true || !mounted) {
      return;
    }
    final ok = await guarded(
        context, () async => app.api.post('/branches/${app.branchId}/reservations/${r['id']}/deposit/$outcome'));
    if (ok) {
      _key.currentState?.reload();
    }
  }

  Future<void> _newBooking() async {
    final app = AppScope.read(context);
    final guest = TextEditingController();
    final mobile = TextEditingController();
    final party = TextEditingController();
    final note = TextEditingController();
    final chosenTables = <int>{};
    var picked = DateTime.now().add(const Duration(hours: 1));

    final go = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.9),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => SafeArea(
          child: Padding(
            padding: EdgeInsets.fromLTRB(
                20, 0, 20, MediaQuery.of(ctx).viewInsets.bottom + MediaQuery.of(ctx).padding.bottom + 20),
            child: SingleChildScrollView(
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
                Text('New booking', style: Theme.of(ctx).textTheme.titleLarge),
                const SizedBox(height: 12),
                field(guest, 'Guest name'),
                field(mobile, 'Mobile (optional)', type: TextInputType.phone),
                field(party, 'Party size', type: TextInputType.number),
                InkWell(
                  borderRadius: BorderRadius.circular(10),
                  onTap: () async {
                    final date = await showDatePicker(
                        context: ctx, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 60)));
                    if (date == null || !ctx.mounted) {
                      return;
                    }
                    final time = await showTimePicker(context: ctx, initialTime: TimeOfDay.fromDateTime(picked));
                    if (time == null) {
                      return;
                    }
                    setSheet(() => picked = DateTime(date.year, date.month, date.day, time.hour, time.minute));
                  },
                  child: InputDecorator(
                    decoration: InputDecoration(labelText: tr('Date and time')),
                    child: Text(fmtDate(picked.toIso8601String())),
                  ),
                ),
                const SizedBox(height: 12),
                if (asRows(app.bootstrap?['tables']).isNotEmpty) ...[
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Text('Tables (optional)',
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: ctx.muted)),
                  ),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    for (final t in asRows(app.bootstrap?['tables']))
                      FilterChip(
                        label: Text('${t['name']}'),
                        selected: chosenTables.contains((t['id'] as num).toInt()),
                        onSelected: (on) => setSheet(() {
                          final id = (t['id'] as num).toInt();
                          on ? chosenTables.add(id) : chosenTables.remove(id);
                        }),
                      ),
                  ]),
                  const SizedBox(height: 12),
                ],
                field(note, 'Note (optional)'),
                FilledButton(
                  onPressed: () => Navigator.pop(ctx, true),
                  child: const Text('Create booking'),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
    final name = guest.text.trim();
    final size = int.tryParse(party.text.trim());
    final phone = mobile.text.trim();
    final noteText = note.text.trim();
    guest.dispose();
    mobile.dispose();
    party.dispose();
    note.dispose();
    if (go != true || name.isEmpty || size == null || size < 1 || !mounted) {
      return;
    }
    final ok = await guarded(context, () async {
      await app.api.post('/branches/${app.branchId}/reservations', body: {
        'guest_name': name,
        if (phone.isNotEmpty) 'guest_mobile': phone,
        'party_size': size,
        'starts_at': picked.toUtc().toIso8601String(),
        if (noteText.isNotEmpty) 'note': noteText,
        if (chosenTables.isNotEmpty) 'table_ids': chosenTables.toList(),
      });
    });
    if (ok) {
      _key.currentState?.reload();
    }
  }

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Reservations')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _newBooking,
        icon: const Icon(Icons.event_available),
        label: const Text('Booking'),
      ),
      body: DataView<List<Map<String, dynamic>>>(
        key: _key,
        path: '/branches/${app.branchId}/reservations',
        transform: asRows,
        builder: (context, rows, raw, reload) => rows.isEmpty
            ? ListView(children: const [SizedBox(height: 160), EmptyState('No bookings yet. Create one with the button below.')])
            : ListView.separated(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(12),
                itemCount: rows.length,
                separatorBuilder: (_, _) => const SizedBox(height: 8),
                itemBuilder: (context, i) => _card(context, rows[i]),
              ),
      ),
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> r) {
    final status = '${r['status']}';
    final tables = asRows(r['tables']);
    final actions = _reservationActions[status] ?? const <String>[];
    final deposit = (r['deposit'] as Map?)?.cast<String, dynamic>() ?? const <String, dynamic>{};
    final depositStatus = '${deposit['status'] ?? 'none'}';
    final canManage = AppScope.of(context).can('reservations.manage');
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text('${r['booking_no']}  -  ${r['guest_name']}', style: const TextStyle(fontWeight: FontWeight.w800))),
            StatusChip(status),
          ]),
          const SizedBox(height: 4),
          Text(
            [
              '${r['party_size']} guests',
              if (r['guest_mobile'] != null) '${r['guest_mobile']}',
              if (r['starts_at'] != null) fmtDate(r['starts_at']),
              if (tables.isNotEmpty) tables.map((t) => '${t['name']}').join(', '),
            ].join('  -  '),
            style: TextStyle(color: context.muted, fontSize: 12.5),
          ),
          if (r['note'] != null && '${r['note']}'.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('${r['note']}', style: TextStyle(color: context.muted, fontSize: 12)),
            ),
          if (depositStatus != 'none')
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                '${tr('Booking money')}: ${fmtMoney(deposit['amount'])}'
                '${deposit['method_name'] != null ? ' (${deposit['method_name']})' : ''}  -  ${titleCase(depositStatus)}',
                style: TextStyle(color: context.muted, fontSize: 12.5),
              ),
            ),
          if (canManage && depositStatus == 'none' && status == 'booked')
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(minimumSize: const Size(0, 36)),
                onPressed: () => _takeDeposit(r),
                icon: const Icon(Icons.savings_outlined),
                label: Text(tr('Booking money')),
              ),
            ),
          if (canManage && depositStatus == 'held')
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Wrap(spacing: 8, children: [
                OutlinedButton(
                  style: OutlinedButton.styleFrom(minimumSize: const Size(0, 36)),
                  onPressed: () => _closeDeposit(r, 'refund'),
                  child: Text(tr('Refund')),
                ),
                OutlinedButton(
                  style: OutlinedButton.styleFrom(minimumSize: const Size(0, 36), foregroundColor: Brand.danger),
                  onPressed: () => _closeDeposit(r, 'forfeit'),
                  child: Text(tr('Keep')),
                ),
              ]),
            ),
          if (actions.isNotEmpty && canManage)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Wrap(spacing: 8, children: [
                for (final a in actions)
                  a == 'cancelled' || a == 'no_show'
                      ? OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            minimumSize: const Size(0, 36),
                            foregroundColor: Brand.danger,
                          ),
                          onPressed: () => _setStatus(r, a),
                          child: Text(titleCase(a)),
                        )
                      : FilledButton.tonal(
                          style: FilledButton.styleFrom(minimumSize: const Size(0, 36)),
                          onPressed: () => _setStatus(r, a),
                          child: Text(titleCase(a)),
                        ),
              ]),
            ),
        ]),
      ),
    );
  }
}
