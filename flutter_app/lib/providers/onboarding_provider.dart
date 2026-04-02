import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/models/location_model.dart';
import '../data/models/category.dart';
import '../data/services/api_service.dart';
import '../data/services/news_service.dart';
import '../data/services/onboarding_service.dart';
import '../data/services/notification_service.dart';
import '../data/services/smart_notification_service.dart';

enum OnboardingStep { location, languages, interests, done }
enum OnboardingLoadState { idle, loading, saving, success, error }

/// Manages the 4-step onboarding flow state.
class OnboardingProvider extends ChangeNotifier {
  OnboardingProvider() : _service = OnboardingService(api: ApiService());

  final OnboardingService _service;

  OnboardingStep      _step      = OnboardingStep.location;
  OnboardingLoadState _loadState = OnboardingLoadState.idle;
  String?             _errorMsg;

  OnboardingStep      get step      => _step;
  OnboardingLoadState get loadState => _loadState;
  String?             get errorMsg  => _errorMsg;
  bool get isSaving => _loadState == OnboardingLoadState.saving;

  // ── Location data ─────────────────────────────────────────────────────
  List<CountryModel>  _countries  = [];
  List<StateModel>    _states     = [];
  List<DistrictModel> _districts  = [];

  CountryModel?  _selectedCountry;
  StateModel?    _selectedState;
  DistrictModel? _selectedDistrict;

  List<CountryModel>  get countries  => _countries;
  List<StateModel>    get states     => _states;
  List<DistrictModel> get districts  => _districts;
  CountryModel?  get selectedCountry  => _selectedCountry;
  StateModel?    get selectedState    => _selectedState;
  DistrictModel? get selectedDistrict => _selectedDistrict;

  // ── Language data ─────────────────────────────────────────────────────
  List<LanguageModel> _languages         = [];
  LanguageModel?      _primaryLanguage;
  List<LanguageModel> _secondaryLanguages = [];

  List<LanguageModel> get languages          => _languages;
  LanguageModel?      get primaryLanguage    => _primaryLanguage;
  List<LanguageModel> get secondaryLanguages => _secondaryLanguages;

  // ── Interest categories ───────────────────────────────────────────────
  List<Category> _allCategories      = [];
  List<int>      _selectedCategoryIds = [];

  List<Category> get allCategories       => _allCategories;
  List<int>      get selectedCategoryIds => _selectedCategoryIds;

  // ── Load geo data ─────────────────────────────────────────────────────

  Future<void> loadCountries() async {
    _loadState = OnboardingLoadState.loading;
    notifyListeners();
    try {
      _countries = await _service.getCountries();
      _loadState = OnboardingLoadState.idle;
    } catch (_) {
      _loadState = OnboardingLoadState.error;
      _errorMsg  = 'Could not load countries.';
    }
    notifyListeners();
  }

  Future<void> selectCountry(CountryModel country) async {
    _selectedCountry  = country;
    _selectedState    = null;
    _selectedDistrict = null;
    _states    = [];
    _districts = [];
    notifyListeners();

    try {
      _states = await _service.getStates(country.id);
    } catch (_) {}
    notifyListeners();
  }

  Future<void> selectState(StateModel state) async {
    _selectedState    = state;
    _selectedDistrict = null;
    _districts = [];
    notifyListeners();

    try {
      _districts = await _service.getDistricts(state.id);
    } catch (_) {}
    notifyListeners();
  }

  void selectDistrict(DistrictModel? district) {
    _selectedDistrict = district;
    notifyListeners();
  }

  // ── Languages ─────────────────────────────────────────────────────────

  Future<void> loadLanguages() async {
    _loadState = OnboardingLoadState.loading;
    notifyListeners();
    try {
      _languages = await _service.getLanguages();
      _loadState = OnboardingLoadState.idle;
    } catch (_) {
      _loadState = OnboardingLoadState.error;
      _errorMsg  = 'Could not load languages.';
    }
    notifyListeners();
  }

  void setPrimaryLanguage(LanguageModel lang) {
    _primaryLanguage = lang;
    _secondaryLanguages.remove(lang);
    notifyListeners();
  }

  void toggleSecondaryLanguage(LanguageModel lang) {
    if (_secondaryLanguages.contains(lang)) {
      _secondaryLanguages.remove(lang);
    } else {
      _secondaryLanguages.add(lang);
    }
    notifyListeners();
  }

  // ── Categories (interests) ────────────────────────────────────────────

  void setAllCategories(List<Category> cats) {
    _allCategories = cats;
    notifyListeners();
  }

  void toggleCategory(int id) {
    if (_selectedCategoryIds.contains(id)) {
      _selectedCategoryIds.remove(id);
    } else {
      _selectedCategoryIds.add(id);
    }
    notifyListeners();
  }

  // ── Step navigation ───────────────────────────────────────────────────

  void nextStep() {
    switch (_step) {
      case OnboardingStep.location:
        _step = OnboardingStep.languages;
      case OnboardingStep.languages:
        _step = OnboardingStep.interests;
      case OnboardingStep.interests:
      case OnboardingStep.done:
        break;
    }
    notifyListeners();
  }

  void prevStep() {
    switch (_step) {
      case OnboardingStep.languages:
        _step = OnboardingStep.location;
      case OnboardingStep.interests:
        _step = OnboardingStep.languages;
      case OnboardingStep.location:
      case OnboardingStep.done:
        break;
    }
    notifyListeners();
  }

  // ── Save to backend ───────────────────────────────────────────────────

  Future<bool> saveAndComplete(String firebaseUid) async {
    _loadState = OnboardingLoadState.saving;
    _errorMsg  = null;
    notifyListeners();

    try {
      // 1. Save location
      if (_selectedCountry != null) {
        await _service.saveLocation(
          firebaseUid: firebaseUid,
          countryId:   _selectedCountry!.id,
          stateId:     _selectedState?.id,
          districtId:  _selectedDistrict?.id,
        );
        // Subscribe to FCM location topics
        await NotificationService.instance.subscribeToLocationTopics(
          countryId:  _selectedCountry!.id,
          stateId:    _selectedState?.id,
          districtId: _selectedDistrict?.id,
        );
      }

      // 2. Save languages
      if (_primaryLanguage != null) {
        await _service.saveLanguages(
          firebaseUid:         firebaseUid,
          primaryLanguageId:   _primaryLanguage!.id,
          secondaryLanguageIds: _secondaryLanguages.map((l) => l.id).toList(),
        );
      }

      // 3. Save interests
      if (_selectedCategoryIds.isNotEmpty) {
        await _service.saveInterests(
          firebaseUid: firebaseUid,
          categoryIds: _selectedCategoryIds,
        );

        // Subscribe to FCM topics for each selected category and persist the
        // category slugs for client-side interest filtering.
        final selectedCats = _allCategories
            .where((c) => _selectedCategoryIds.contains(c.id))
            .toList();
        final slugs = selectedCats.map((c) => c.slug).toList();

        await NotificationService.instance.subscribeToCategoryTopics(slugs);
        await SmartNotificationService.instance.setInterestCategories(slugs);
      }

      // Mark onboarding as done in SharedPreferences
      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool(OnboardingPrefs.completed, true);

      _step      = OnboardingStep.done;
      _loadState = OnboardingLoadState.success;
      notifyListeners();
      return true;
    } catch (_) {
      _loadState = OnboardingLoadState.error;
      _errorMsg  = 'Could not save preferences. Please try again.';
      notifyListeners();
      return false;
    }
  }

  @override
  void dispose() {
    _service.dispose();
    super.dispose();
  }
}
