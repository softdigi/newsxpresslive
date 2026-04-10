import 'package:flutter/material.dart';
import '../../data/models/mandi_model.dart';
import '../../data/services/mandi_service.dart';
import '../../core/constants/app_colors.dart';

/// Screen: Manage price alerts — active + triggered history.
class MyAlertsScreen extends StatefulWidget {
  const MyAlertsScreen({super.key, this.firebaseUid = 'guest'});
  final String firebaseUid;

  @override
  State<MyAlertsScreen> createState() => _MyAlertsScreenState();
}

class _MyAlertsScreenState extends State<MyAlertsScreen> {
  final _svc = MandiService();
  List<MandiAlert> _alerts = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final list = await _svc.getAlerts(widget.firebaseUid);
    if (mounted) setState(() {
      _alerts  = list;
      _loading = false;
    });
  }

  Future<void> _toggle(MandiAlert alert) async {
    await _svc.toggleAlert(alertId: alert.id, firebaseUid: widget.firebaseUid);
    await _load();
  }

  Future<void> _delete(MandiAlert alert) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('अलर्ट हटाएं?'),
        content: Text('${alert.commodity} का अलर्ट हटाएं?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('रद्द करें')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('हटाएं'),
          ),
        ],
      ),
    );
    if (confirm == true) {
      await _svc.deleteAlert(alertId: alert.id, firebaseUid: widget.firebaseUid);
      await _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(
        title: const Text('मेरे मूल्य अलर्ट'),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _load),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _alerts.isEmpty
              ? _buildEmpty()
              : _buildList(isDark),
    );
  }

  Widget _buildEmpty() => const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.notifications_off_outlined, size: 48, color: Colors.grey),
            SizedBox(height: 12),
            Text('कोई अलर्ट नहीं'),
            SizedBox(height: 4),
            Text('मंडी भाव स्क्रीन से अलर्ट सेट करें',
                style: TextStyle(color: Colors.grey, fontSize: 12)),
          ],
        ),
      );

  Widget _buildList(bool isDark) {
    final active    = _alerts.where((a) => a.isActive).toList();
    final triggered = _alerts.where((a) => a.lastTriggered != null).toList();
    final inactive  = _alerts.where((a) => !a.isActive && a.lastTriggered == null).toList();

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          if (active.isNotEmpty) ...[
            _sectionHeader('✅ सक्रिय अलर्ट (${active.length})', isDark),
            ...active.map((a) => _AlertTile(alert: a, isDark: isDark, onToggle: _toggle, onDelete: _delete)),
            const SizedBox(height: 16),
          ],
          if (triggered.isNotEmpty) ...[
            _sectionHeader('🔔 ट्रिगर हुए अलर्ट (${triggered.length})', isDark),
            ...triggered.map((a) => _AlertTile(alert: a, isDark: isDark, onToggle: _toggle, onDelete: _delete)),
            const SizedBox(height: 16),
          ],
          if (inactive.isNotEmpty) ...[
            _sectionHeader('⏸️ निष्क्रिय अलर्ट (${inactive.length})', isDark),
            ...inactive.map((a) => _AlertTile(alert: a, isDark: isDark, onToggle: _toggle, onDelete: _delete)),
          ],
        ],
      ),
    );
  }

  Widget _sectionHeader(String title, bool isDark) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Text(
          title,
          style: TextStyle(
            fontWeight: FontWeight.bold,
            fontSize: 14,
            color: isDark ? AppColors.textSecondaryDark : AppColors.textSecondaryLight,
          ),
        ),
      );
}

// ── Alert Tile ────────────────────────────────────────────────────────────────

class _AlertTile extends StatelessWidget {
  const _AlertTile({
    required this.alert,
    required this.isDark,
    required this.onToggle,
    required this.onDelete,
  });

  final MandiAlert alert;
  final bool       isDark;
  final Future<void> Function(MandiAlert) onToggle;
  final Future<void> Function(MandiAlert) onDelete;

  @override
  Widget build(BuildContext context) {
    final isAbove    = alert.alertType == 'above';
    final typeIcon   = isAbove ? '↑' : '↓';
    final typeColor  = isAbove ? Colors.green : Colors.red;

    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      color: isDark ? AppColors.cardDark : AppColors.cardLight,
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: typeColor.withAlpha(30),
          child: Text(typeIcon,
              style: TextStyle(color: typeColor, fontWeight: FontWeight.bold, fontSize: 18)),
        ),
        title: Text(
          '${alert.commodity} ${isAbove ? 'से ऊपर' : 'से नीचे'} ₹${alert.targetPrice.toStringAsFixed(0)}',
          style: const TextStyle(fontWeight: FontWeight.w600),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(alert.mandi, style: const TextStyle(fontSize: 12)),
            if (alert.lastTriggered != null)
              Text('आखिरी बार: ${alert.lastTriggered}',
                  style: const TextStyle(fontSize: 11, color: Colors.orange)),
          ],
        ),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Switch(
              value: alert.isActive,
              activeColor: AppColors.primary,
              onChanged: (_) => onToggle(alert),
            ),
            IconButton(
              icon: const Icon(Icons.delete_outline_rounded, color: Colors.red),
              onPressed: () => onDelete(alert),
            ),
          ],
        ),
      ),
    );
  }
}
