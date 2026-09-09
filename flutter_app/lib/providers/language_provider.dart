import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart' show Locale, TextDirection;
import '../data/models/location_model.dart';
import '../data/services/language_service.dart';
import '../data/services/api_service.dart';

enum LanguageLoadState { idle, loading, saving, success, error }

/// RTL language codes — kept in sync with migration_v15_global_languages.sql.
const Set<String> _rtlCodes = {'ur', 'ar', 'fa'};

/// Manages the user's news-reading language preferences.
///
/// Selected language codes are persisted locally and synced to the backend
/// when the user is authenticated.
///
/// Also exposes [appLocale] and [appTextDirection] so the root MaterialApp
/// can rebuild when the UI language changes.
class LanguageProvider extends ChangeNotifier {
  LanguageProvider() : _service = LanguageService(api: ApiService());

  final LanguageService _service;

  List<LanguageModel>   _supported   = [];
  List<String>          _selected    = ['hi', 'en'];
  String                _appLangCode = 'en'; // primary UI language
  LanguageLoadState     _loadState   = LanguageLoadState.idle;
  String?               _errorMsg;

  List<LanguageModel>   get supported   => _supported;
  List<String>          get selected    => _selected;
  LanguageLoadState     get loadState   => _loadState;
  String?               get errorMsg    => _errorMsg;
  bool get isSaving => _loadState == LanguageLoadState.saving;

  /// The current UI locale derived from [_appLangCode].
  Locale get appLocale => Locale(_appLangCode);

  /// Whether the current UI language is RTL.
  bool get isRtl => _rtlCodes.contains(_appLangCode);

  /// Flutter [TextDirection] for the current UI language.
  TextDirection get textDirection =>
      isRtl ? TextDirection.rtl : TextDirection.ltr;

  // ── Initialise ────────────────────────────────────────────────────────────

  Future<void> init() async {
    _selected    = await _service.getSelectedCodes();
    _appLangCode = _selected.isNotEmpty ? _selected.first : 'en';
    notifyListeners();
    await loadSupported();
  }

  // ── Load supported languages from backend ─────────────────────────────────

  Future<void> loadSupported() async {
    _loadState = LanguageLoadState.loading;
    notifyListeners();
    try {
      _supported = await _service.getSupportedLanguages();
      _loadState = LanguageLoadState.idle;
    } catch (_) {
      _loadState = LanguageLoadState.error;
      _errorMsg  = 'Could not load languages. Please try again.';
    }
    notifyListeners();
  }

  // ── Toggle a language code ─────────────────────────────────────────────────

  void toggleCode(String code) {
    if (_selected.contains(code)) {
      if (_selected.length <= 1) return; // at least 1 must remain
      _selected = List.of(_selected)..remove(code);
    } else {
      _selected = List.of(_selected)..add(code);
    }
    notifyListeners();
  }

  bool isSelected(String code) => _selected.contains(code);

  // ── Check if a language is RTL ─────────────────────────────────────────────

  static bool isRtlCode(String code) => _rtlCodes.contains(code);

  // ── Save ───────────────────────────────────────────────────────────────────

  Future<bool> save() async {
    _loadState = LanguageLoadState.saving;
    _errorMsg  = null;
    notifyListeners();
    try {
      await _service.saveSelectedCodes(_selected);
      // Update UI locale to first selected language
      _appLangCode = _selected.isNotEmpty ? _selected.first : 'en';
      _loadState   = LanguageLoadState.success;
      notifyListeners();
      return true;
    } catch (_) {
      _loadState = LanguageLoadState.error;
      _errorMsg  = 'Could not save preferences. Please try again.';
      notifyListeners();
      return false;
    }
  }

  // ── Initialise from saved codes (no network) ──────────────────────────────

  Future<void> loadFromPrefs() async {
    _selected    = await _service.getSelectedCodes();
    _appLangCode = _selected.isNotEmpty ? _selected.first : 'en';
    notifyListeners();
  }

  @override
  void dispose() {
    _service.dispose();
    super.dispose();
  }
}
