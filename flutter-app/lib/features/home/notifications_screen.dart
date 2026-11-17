import 'package:flutter/material.dart' hide Text;

import '../../core/theme.dart';
import '../../ui/widgets.dart';
import '../../ui/tr_text.dart';
import '../../core/l10n.dart';

/// The bell's alerts: low stock, wastage spikes and the like, the same list as
/// the web panel's bell. Read state is kept per person on the server.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<Map<String, dynamic>> _items = [];
  var _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final app = AppScope.read(context);
    await guarded(context, () async {
      final res = await app.api.get('/notifications') as Map;
      if (mounted) {
        setState(() {
          _items = asRows(res['notifications']);
          _loading = false;
        });
      }
    });
    if (!mounted) {
      return;
    }
    setState(() => _loading = false);
  }

  Future<void> _markAllRead() async {
    final app = AppScope.read(context);
    final ok = await guarded(context, () async => app.api.post('/notifications/read'));
    if (ok) {
      await _load();
    }
  }

  IconData _iconFor(String name) {
    if (name.contains('triangle') || name.contains('warning')) {
      return Icons.warning_amber_rounded;
    }
    if (name.contains('boxes') || name.contains('box')) {
      return Icons.inventory_2_outlined;
    }
    if (name.contains('trash')) {
      return Icons.delete_outline;
    }
    if (name.contains('coins') || name.contains('money')) {
      return Icons.payments_outlined;
    }
    return Icons.notifications_none;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(tr('Notifications')),
        actions: [
          if (_items.isNotEmpty)
            IconButton(tooltip: tr('Mark all read'), icon: const Icon(Icons.done_all), onPressed: _markAllRead),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(12),
                children: [
                  if (_items.isEmpty) const EmptyState('No new notifications.'),
                  for (final item in _items)
                    Card(
                      child: ListTile(
                        leading: Icon(_iconFor('${item['icon']}'), color: Brand.warning),
                        title: Text('${item['title']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text('${item['body']}'),
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}

/// The bell in the home app bar: shows how many alerts are unread and opens the list.
class AlertBell extends StatefulWidget {
  const AlertBell({super.key});

  @override
  State<AlertBell> createState() => _AlertBellState();
}

class _AlertBellState extends State<AlertBell> {
  int _count = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final app = AppScope.read(context);
    var count = 0;
    final ok = await guarded(context, () async {
      final res = await app.api.get('/notifications') as Map;
      count = toNum(res['count']).toInt();
    });
    if (ok && mounted) {
      setState(() => _count = count);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Stack(alignment: Alignment.center, children: [
      IconButton(
        tooltip: tr('Notifications'),
        icon: const Icon(Icons.notifications_none),
        onPressed: () async {
          await Navigator.push(context, MaterialPageRoute(builder: (_) => const NotificationsScreen()));
          _load();
        },
      ),
      if (_count > 0)
        Positioned(
          right: 6,
          top: 8,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            decoration: BoxDecoration(color: Brand.danger, borderRadius: BorderRadius.circular(10)),
            child: Text('$_count', style: const TextStyle(fontSize: 10, color: Colors.white)),
          ),
        ),
    ]);
  }
}
