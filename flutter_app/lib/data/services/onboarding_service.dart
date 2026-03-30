import '../models/location_model.dart';
import '../models/category.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Service for the onboarding flow:
/// - Fetch geo data (countries, states, districts, languages)
/// - Save location, language preferences and interest categories to backend
class OnboardingService {
  OnboardingService({required ApiService api}) : _api = api;

  final ApiService _api;

  // ── Geo data ──────────────────────────────────────────────────────────

  Future<List<CountryModel>> getCountries() async {
    final data = await _api.get(ApiEndpoints.countries);
    if (data is! Map) return [];
    final list = data['data'];
    if (list is! List) return [];
    return list
        .map((e) => CountryModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<List<StateModel>> getStates(int countryId) async {
    final data = await _api.get(
      ApiEndpoints.states,
      queryParams: {'country_id': countryId.toString()},
    );
    if (data is! Map) return [];
    final list = data['data'];
    if (list is! List) return [];
    return list
        .map((e) => StateModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<List<DistrictModel>> getDistricts(int stateId) async {
    final data = await _api.get(
      ApiEndpoints.districts,
      queryParams: {'state_id': stateId.toString()},
    );
    if (data is! Map) return [];
    final list = data['data'];
    if (list is! List) return [];
    return list
        .map((e) => DistrictModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<List<LanguageModel>> getLanguages() async {
    final data = await _api.get(ApiEndpoints.languages);
    if (data is! Map) return [];
    final list = data['data'];
    if (list is! List) return [];
    return list
        .map((e) => LanguageModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  // ── Save preferences ──────────────────────────────────────────────────

  Future<bool> saveLocation({
    required String firebaseUid,
    required int    countryId,
    int?            stateId,
    int?            districtId,
  }) async {
    try {
      await _api.postJson(ApiEndpoints.saveLocation, body: {
        'firebase_uid': firebaseUid,
        'country_id':   countryId,
        if (stateId    != null) 'state_id':    stateId,
        if (districtId != null) 'district_id': districtId,
      });
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<bool> saveLanguages({
    required String firebaseUid,
    required int    primaryLanguageId,
    List<int>       secondaryLanguageIds = const [],
  }) async {
    try {
      final languages = [
        {'language_id': primaryLanguageId, 'priority': 1},
        for (int i = 0; i < secondaryLanguageIds.length; i++)
          {'language_id': secondaryLanguageIds[i], 'priority': i + 2},
      ];
      await _api.postJson(ApiEndpoints.saveLanguages, body: {
        'firebase_uid': firebaseUid,
        'languages':    languages,
      });
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<bool> saveInterests({
    required String firebaseUid,
    required List<int> categoryIds,
  }) async {
    try {
      await _api.postJson(ApiEndpoints.saveInterests, body: {
        'firebase_uid': firebaseUid,
        'categories':   categoryIds,
      });
      return true;
    } catch (_) {
      return false;
    }
  }

  void dispose() => _api.dispose();
}

/// Onboarding state saved locally in SharedPreferences.
/// Keyed under [OnboardingPrefs].
class OnboardingPrefs {
  static const String completed = 'onboarding_completed';
}
