import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'dart:convert';

class OfflinePackInfo {
  final String packDate;
  final String languageCode;
  final int? stateId;
  final int articlesCount;
  final int sizeKb;
  final String downloadUrl;
  final String expiresAt;

  const OfflinePackInfo({
    required this.packDate,
    required this.languageCode,
    this.stateId,
    required this.articlesCount,
    required this.sizeKb,
    required this.downloadUrl,
    required this.expiresAt,
  });

  factory OfflinePackInfo.fromJson(Map<String, dynamic> j) => OfflinePackInfo(
        packDate: j['pack_date'] ?? '',
        languageCode: j['language_code'] ?? 'hi',
        stateId: j['state_id'] as int?,
        articlesCount: j['articles_count'] ?? 0,
        sizeKb: j['size_kb'] ?? 0,
        downloadUrl: j['download_url'] ?? '',
        expiresAt: j['expires_at'] ?? '',
      );
}

class OfflinePackService extends ChangeNotifier {
  static const _baseUrl = 'https://newsxpresslive.com/web/api/offline/pack.php';
  static const _prefsKey = 'offline_pack_metadata';
  static const _autoDownloadKey = 'offline_auto_download';
  static const _wifiOnlyKey = 'offline_wifi_only';
  static const _langKey = 'offline_pack_lang';

  List<Map<String, dynamic>> _downloadedPacks = [];
  bool _isDownloading = false;
  String? _downloadError;

  List<Map<String, dynamic>> get downloadedPacks => _downloadedPacks;
  bool get isDownloading => _isDownloading;
  String? get downloadError => _downloadError;

  int get totalStorageKb =>
      _downloadedPacks.fold(0, (sum, p) => sum + (p['size_kb'] as int? ?? 0));

  OfflinePackService() {
    _loadMetadata();
  }

  Future<void> _loadMetadata() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_prefsKey);
    if (raw != null) {
      try {
        _downloadedPacks =
            List<Map<String, dynamic>>.from(jsonDecode(raw) as List);
        notifyListeners();
      } catch (_) {}
    }
  }

  Future<void> _saveMetadata() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_prefsKey, jsonEncode(_downloadedPacks));
  }

  Future<bool> getAutoDownload() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_autoDownloadKey) ?? false;
  }

  Future<void> setAutoDownload(bool val) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_autoDownloadKey, val);
    notifyListeners();
  }

  Future<bool> getWifiOnly() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_wifiOnlyKey) ?? true;
  }

  Future<void> setWifiOnly(bool val) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_wifiOnlyKey, val);
    notifyListeners();
  }

  Future<String> getPackLang() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_langKey) ?? 'hi';
  }

  Future<void> setPackLang(String lang) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_langKey, lang);
    notifyListeners();
  }

  /// Fetch pack metadata from server for [date]/[lang]/[stateId].
  Future<OfflinePackInfo?> fetchPackInfo({
    String date = 'today',
    String lang = 'hi',
    int? stateId,
  }) async {
    final uri = Uri.parse(
        '$_baseUrl?date=$date&lang=$lang${stateId != null ? "&state_id=$stateId" : ""}');
    try {
      final res = await http.get(uri).timeout(const Duration(seconds: 15));
      final json = jsonDecode(res.body) as Map<String, dynamic>;
      if (json['success'] == true) return OfflinePackInfo.fromJson(json);
    } catch (_) {}
    return null;
  }

  /// Download a pack (gzip JSON) and save to local storage.
  Future<bool> downloadPack(OfflinePackInfo info) async {
    _isDownloading = true;
    _downloadError = null;
    notifyListeners();

    try {
      final dir = await getApplicationDocumentsDirectory();
      final packsDir = Directory('${dir.path}/offline_packs');
      if (!packsDir.existsSync()) packsDir.createSync(recursive: true);

      final filename =
          'pack_${info.packDate}_${info.languageCode}${info.stateId != null ? "_${info.stateId}" : ""}.json.gz';
      final file = File('${packsDir.path}/$filename');

      final res = await http
          .get(Uri.parse(info.downloadUrl))
          .timeout(const Duration(minutes: 5));

      if (res.statusCode != 200) {
        _downloadError = 'Download failed (HTTP ${res.statusCode})';
        return false;
      }

      await file.writeAsBytes(res.bodyBytes);

      // Register in metadata
      _downloadedPacks.removeWhere((p) =>
          p['pack_date'] == info.packDate &&
          p['language_code'] == info.languageCode &&
          p['state_id'] == info.stateId);

      _downloadedPacks.insert(0, {
        'pack_date': info.packDate,
        'language_code': info.languageCode,
        'state_id': info.stateId,
        'articles_count': info.articlesCount,
        'size_kb': info.sizeKb,
        'local_path': file.path,
        'downloaded_at': DateTime.now().toIso8601String(),
        'expires_at': info.expiresAt,
      });

      await _saveMetadata();
      await _cleanOldPacks();
      return true;
    } catch (e) {
      _downloadError = 'Error: $e';
      return false;
    } finally {
      _isDownloading = false;
      notifyListeners();
    }
  }

  /// Delete a specific pack.
  Future<void> deletePack(int index) async {
    if (index < 0 || index >= _downloadedPacks.length) return;
    final meta = _downloadedPacks[index];
    final path = meta['local_path'] as String?;
    if (path != null) {
      try {
        final f = File(path);
        if (await f.exists()) await f.delete();
      } catch (_) {}
    }
    _downloadedPacks.removeAt(index);
    await _saveMetadata();
    notifyListeners();
  }

  /// Delete packs older than 7 days.
  Future<void> _cleanOldPacks() async {
    final cutoff =
        DateTime.now().subtract(const Duration(days: 7));
    final toRemove = <int>[];
    for (int i = 0; i < _downloadedPacks.length; i++) {
      final downloaded =
          DateTime.tryParse(_downloadedPacks[i]['downloaded_at'] ?? '');
      if (downloaded != null && downloaded.isBefore(cutoff)) {
        toRemove.add(i);
      }
    }
    for (final i in toRemove.reversed) {
      await deletePack(i);
    }
  }
}
