import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../core/constants/app_colors.dart';
import '../../../data/services/offline_pack_service.dart';

/// Offline Daily Pack download & management screen.
class OfflinePackScreen extends StatefulWidget {
  const OfflinePackScreen({super.key});

  @override
  State<OfflinePackScreen> createState() => _OfflinePackScreenState();
}

class _OfflinePackScreenState extends State<OfflinePackScreen> {
  OfflinePackInfo? _todayPack;
  bool _fetchingMeta = false;

  bool _autoDownload = false;
  bool _wifiOnly = true;
  String _packLang = 'hi';

  @override
  void initState() {
    super.initState();
    _loadSettings();
    _fetchTodayMeta();
  }

  Future<void> _loadSettings() async {
    final svc = context.read<OfflinePackService>();
    final auto  = await svc.getAutoDownload();
    final wifi  = await svc.getWifiOnly();
    final lang  = await svc.getPackLang();
    if (mounted) setState(() { _autoDownload = auto; _wifiOnly = wifi; _packLang = lang; });
  }

  Future<void> _fetchTodayMeta() async {
    setState(() => _fetchingMeta = true);
    final svc = context.read<OfflinePackService>();
    final info = await svc.fetchPackInfo(date: 'today', lang: _packLang);
    if (mounted) setState(() { _todayPack = info; _fetchingMeta = false; });
  }

  Future<void> _downloadToday() async {
    if (_todayPack == null) return;
    final svc = context.read<OfflinePackService>();
    final ok  = await svc.downloadPack(_todayPack!);
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(ok ? 'Pack downloaded successfully!' : (svc.downloadError ?? 'Download failed')),
        backgroundColor: ok ? Colors.green : AppColors.primary,
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Offline Pack')),
      body: Consumer<OfflinePackService>(
        builder: (ctx, svc, _) {
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              // Today's pack download card
              _TodayPackCard(
                info: _todayPack,
                fetchingMeta: _fetchingMeta,
                isDownloading: svc.isDownloading,
                onDownload: _downloadToday,
                onRefresh: _fetchTodayMeta,
              ),
              const SizedBox(height: 16),

              // Storage info
              _StorageBar(totalKb: svc.totalStorageKb),
              const SizedBox(height: 16),

              // Settings
              _SettingsCard(
                autoDownload: _autoDownload,
                wifiOnly: _wifiOnly,
                packLang: _packLang,
                onAutoDownload: (v) async {
                  await svc.setAutoDownload(v);
                  setState(() => _autoDownload = v);
                },
                onWifiOnly: (v) async {
                  await svc.setWifiOnly(v);
                  setState(() => _wifiOnly = v);
                },
                onLangChanged: (v) async {
                  if (v == null) return;
                  await svc.setPackLang(v);
                  setState(() => _packLang = v);
                  _fetchTodayMeta();
                },
              ),
              const SizedBox(height: 16),

              // Downloaded packs list
              if (svc.downloadedPacks.isNotEmpty) ...[
                const Text('Downloaded Packs',
                    style:
                        TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                ...svc.downloadedPacks.asMap().entries.map((e) =>
                    _PackRow(meta: e.value, index: e.key, service: svc)),
              ],
            ],
          );
        },
      ),
    );
  }
}

class _TodayPackCard extends StatelessWidget {
  final OfflinePackInfo? info;
  final bool fetchingMeta;
  final bool isDownloading;
  final VoidCallback onDownload;
  final VoidCallback onRefresh;

  const _TodayPackCard({
    required this.info,
    required this.fetchingMeta,
    required this.isDownloading,
    required this.onDownload,
    required this.onRefresh,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Text('📦', style: TextStyle(fontSize: 24)),
                const SizedBox(width: 8),
                const Expanded(
                    child: Text('आज का Pack',
                        style: TextStyle(
                            fontSize: 16, fontWeight: FontWeight.bold))),
                if (fetchingMeta)
                  const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2))
                else
                  IconButton(
                      icon: const Icon(Icons.refresh, size: 20),
                      onPressed: onRefresh),
              ],
            ),
            if (info != null) ...[
              const SizedBox(height: 8),
              Text('${info!.articlesCount} articles • ${info!.sizeKb} KB',
                  style: const TextStyle(color: Colors.grey)),
              Text('Expires: ${info!.expiresAt}',
                  style:
                      const TextStyle(fontSize: 12, color: Colors.grey)),
            ] else if (!fetchingMeta) ...[
              const SizedBox(height: 8),
              const Text('Pack available after 5am', style: TextStyle(color: Colors.grey)),
            ],
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.primary,
                    foregroundColor: Colors.white),
                onPressed: (info != null && !isDownloading) ? onDownload : null,
                icon: isDownloading
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(
                            color: Colors.white, strokeWidth: 2))
                    : const Icon(Icons.download_rounded),
                label: Text(isDownloading
                    ? 'Downloading...'
                    : 'आज का Pack Download करें'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StorageBar extends StatelessWidget {
  final int totalKb;
  const _StorageBar({required this.totalKb});

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            const Icon(Icons.storage_rounded, color: AppColors.primary),
            const SizedBox(width: 12),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Storage Used',
                    style: TextStyle(fontSize: 12, color: Colors.grey)),
                Text('${(totalKb / 1024).toStringAsFixed(1)} MB',
                    style: const TextStyle(fontWeight: FontWeight.bold)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _SettingsCard extends StatelessWidget {
  final bool autoDownload;
  final bool wifiOnly;
  final String packLang;
  final ValueChanged<bool> onAutoDownload;
  final ValueChanged<bool> onWifiOnly;
  final ValueChanged<String?> onLangChanged;

  const _SettingsCard({
    required this.autoDownload,
    required this.wifiOnly,
    required this.packLang,
    required this.onAutoDownload,
    required this.onWifiOnly,
    required this.onLangChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Column(
          children: [
            SwitchListTile(
              title: const Text('Auto-download'),
              subtitle: const Text('Daily pack automatically download karo'),
              value: autoDownload,
              onChanged: onAutoDownload,
              activeColor: AppColors.primary,
            ),
            SwitchListTile(
              title: const Text('Sirf WiFi pe'),
              value: wifiOnly,
              onChanged: onWifiOnly,
              activeColor: AppColors.primary,
            ),
            ListTile(
              title: const Text('Pack Language'),
              trailing: DropdownButton<String>(
                value: packLang,
                underline: const SizedBox(),
                items: const [
                  DropdownMenuItem(value: 'hi', child: Text('हिंदी')),
                  DropdownMenuItem(value: 'en', child: Text('English')),
                  DropdownMenuItem(value: 'mr', child: Text('मराठी')),
                  DropdownMenuItem(value: 'gu', child: Text('ગુજરાતી')),
                ],
                onChanged: onLangChanged,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PackRow extends StatelessWidget {
  final Map<String, dynamic> meta;
  final int index;
  final OfflinePackService service;

  const _PackRow(
      {required this.meta, required this.index, required this.service});

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: ListTile(
        leading: const Text('📦', style: TextStyle(fontSize: 22)),
        title: Text(meta['pack_date'] ?? '-'),
        subtitle: Text(
            '${meta['articles_count']} articles • ${meta['size_kb']} KB • ${meta['language_code']}'),
        trailing: IconButton(
          icon: const Icon(Icons.delete_outline, color: Colors.red),
          onPressed: () async {
            final ok = await showDialog<bool>(
              context: context,
              builder: (_) => AlertDialog(
                title: const Text('Delete Pack?'),
                content: Text('Delete ${meta['pack_date']} pack?'),
                actions: [
                  TextButton(
                      onPressed: () => Navigator.pop(context, false),
                      child: const Text('Cancel')),
                  TextButton(
                      onPressed: () => Navigator.pop(context, true),
                      child: const Text('Delete',
                          style: TextStyle(color: Colors.red))),
                ],
              ),
            );
            if (ok == true) await service.deletePack(index);
          },
        ),
      ),
    );
  }
}
