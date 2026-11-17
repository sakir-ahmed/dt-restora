import 'package:flutter/material.dart' hide Text;

import '../../core/theme.dart';
import '../../ui/widgets.dart';
import '../../ui/tr_text.dart';
import '../../core/l10n.dart';

/// Roles and what each may do: the same matrix as the web panel's Roles screen.
class RolesScreen extends StatelessWidget {
  const RolesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Roles and permissions'))),
      body: DataView<Map<String, dynamic>>(
        path: '/roles',
        transform: (raw) => Map<String, dynamic>.from(raw as Map),
        builder: (context, data, raw, reload) {
          final roles = asRows(data['roles']);
          return ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(12),
            children: [
              if (roles.isEmpty) const EmptyState('No roles yet.'),
              for (final role in roles)
                Card(
                  child: ListTile(
                    title: Row(children: [
                      Expanded(child: Text('${role['label']}', style: const TextStyle(fontWeight: FontWeight.w700))),
                      if (role['modified'] == true)
                        Padding(
                          padding: const EdgeInsets.only(left: 6),
                          child: Chip(label: Text(tr('changed')), visualDensity: VisualDensity.compact),
                        ),
                    ]),
                    subtitle: Text(
                      '${role['is_default'] == true ? tr('Standard role') : tr('Custom role')}'
                      '  -  ${role['users_count']} ${role['users_count'] == 1 ? tr('person') : tr('people')}',
                    ),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => RoleEditorScreen(
                          role: role,
                          groups: (data['groups'] as Map).cast<String, List<dynamic>>(),
                          requiredPermission: '${data['required']}',
                          onChanged: reload,
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          );
        },
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => const RoleEditorScreen(role: null, groups: {}, requiredPermission: 'admin.access')),
        ),
        icon: const Icon(Icons.add),
        label: Text(tr('Role')),
      ),
    );
  }
}

/// Creates a role or edits one: wording (custom roles only) and the permission matrix.
/// The permission groups are fetched here when the list screen did not pass them.
class RoleEditorScreen extends StatefulWidget {
  const RoleEditorScreen({super.key, required this.role, required this.groups, required this.requiredPermission, this.onChanged});

  final Map<String, dynamic>? role;
  final Map<String, List<dynamic>> groups;
  final String requiredPermission;
  final Future<void> Function()? onChanged;

  @override
  State<RoleEditorScreen> createState() => _RoleEditorScreenState();
}

class _RoleEditorScreenState extends State<RoleEditorScreen> {
  final _label = TextEditingController();
  final _labelBn = TextEditingController();
  final _description = TextEditingController();
  final _checked = <String, bool>{};
  var _groups = <String, List<dynamic>>{};
  var _allBranches = false;
  var _saving = false;

  Map<String, dynamic>? get _role => widget.role;

  bool get _isDefault => _role?['is_default'] == true;

  @override
  void initState() {
    super.initState();
    final role = _role;
    _label.text = '${role?['label_en'] ?? role?['label'] ?? ''}';
    _labelBn.text = '${role?['label_bn'] ?? ''}';
    _description.text = '${role?['description'] ?? ''}';
    _allBranches = role?['all_branches'] == true;
    for (final permission in (role?['permissions'] as List? ?? [])) {
      _checked['$permission'] = true;
    }
    _groups = widget.groups;
    if (_groups.isEmpty) {
      _loadGroups();
    }
  }

  Future<void> _loadGroups() async {
    final app = AppScope.read(context);
    final ok = await guarded(context, () async {
      final res = await app.api.get('/roles') as Map;
      final groups = ((res['groups'] ?? const {}) as Map).cast<String, List<dynamic>>();
      final required = '${res['required']}';
      if (mounted) {
        setState(() {
          _groups = groups;
          _checked[required] = true;
        });
      }
    });
    if (!ok && mounted) {
      Navigator.pop(context);
    }
  }

  @override
  void dispose() {
    _label.dispose();
    _labelBn.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || _label.text.trim().isEmpty && _role == null) {
      return;
    }
    final app = AppScope.read(context);
    final permissions = _checked.keys.where((key) => _checked[key]!).toList();
    setState(() => _saving = true);
    final ok = await guarded(context, () async {
      if (_role == null) {
        await app.api.post('/roles', body: {
          'label': _label.text.trim(),
          if (_labelBn.text.trim().isNotEmpty) 'label_bn': _labelBn.text.trim(),
          if (_description.text.trim().isNotEmpty) 'description': _description.text.trim(),
          'all_branches': _allBranches,
          'permissions': permissions,
        });
      } else {
        await app.api.put('/roles/${_role!['id']}', body: {
          if (!_isDefault) ...{
            'label': _label.text.trim(),
            'label_bn': _labelBn.text.trim().isEmpty ? null : _labelBn.text.trim(),
            'description': _description.text.trim().isEmpty ? null : _description.text.trim(),
          },
          'all_branches': _allBranches,
          'permissions': permissions,
        });
      }
    });
    if (!mounted) {
      return;
    }
    setState(() => _saving = false);
    if (ok) {
      await widget.onChanged?.call();
      if (mounted) {
        Navigator.pop(context);
      }
    }
  }

  Future<void> _reset() async {
    if (_role == null) {
      return;
    }
    final app = AppScope.read(context);
    final go = await confirm(context, tr('Reset this role to its default permissions?'), action: tr('Reset'));
    if (go != true || !mounted) {
      return;
    }
    final ok = await guarded(context, () async => app.api.post('/roles/${_role!['id']}/reset'));
    if (ok && mounted) {
      await widget.onChanged?.call();
      if (mounted) {
        Navigator.pop(context);
      }
    }
  }

  Future<void> _delete() async {
    if (_role == null) {
      return;
    }
    final app = AppScope.read(context);
    final go = await confirm(
      context,
      'Delete ${_role!['label']}?',
      body: tr('Only a custom role nobody uses can be deleted.'),
      action: tr('Delete'),
    );
    if (go != true || !mounted) {
      return;
    }
    final ok = await guarded(context, () async => app.api.delete('/roles/${_role!['id']}'));
    if (ok && mounted) {
      await widget.onChanged?.call();
      if (mounted) {
        Navigator.pop(context);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_role == null ? tr('New role') : '${_role!['label']}')),
      body: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(12),
        children: [
          if (_role == null || !_isDefault) ...[
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  field(_label, tr('Name')),
                  const SizedBox(height: 8),
                  field(_labelBn, tr('Name in Bangla (optional)')),
                  const SizedBox(height: 8),
                  field(_description, tr('Description (optional)')),
                ]),
              ),
            ),
            SwitchListTile(
              value: _allBranches,
              onChanged: (v) => setState(() => _allBranches = v),
              title: Text(tr('May see every branch')),
            ),
            const SizedBox(height: 4),
          ],
          const SectionTitle('Permissions'),
          if (_groups.isEmpty)
            const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
          for (final entry in _groups.entries)
            Card(
              child: ExpansionTile(
                initiallyExpanded: _groups.entries.first.key == entry.key,
                title: Text(titleCase(entry.key), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text('${entry.value.where((p) => _checked['$p'] == true).length} / ${entry.value.length}'),
                children: [
                  for (final permission in entry.value)
                    SwitchListTile(
                      dense: true,
                      value: _checked['$permission'] == true,
                      title: Text('$permission'),
                      subtitle: '$permission' == widget.requiredPermission ? Text(tr('Needed to sign in')) : null,
                      onChanged: '$permission' == widget.requiredPermission
                          ? null
                          : (v) => setState(() => _checked['$permission'] = v),
                    ),
                ],
              ),
            ),
          const SizedBox(height: 8),
          FilledButton.icon(
            onPressed: _saving ? null : _save,
            icon: _saving
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.check),
            label: Text(tr('Save')),
          ),
          if (_isDefault)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: OutlinedButton.icon(
                onPressed: _reset,
                icon: const Icon(Icons.restart_alt),
                label: Text(tr('Reset to default')),
              ),
            ),
          if (_role != null && !_isDefault)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: Brand.danger),
                onPressed: _delete,
                icon: const Icon(Icons.delete_outline),
                label: Text(tr('Delete role')),
              ),
            ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}
