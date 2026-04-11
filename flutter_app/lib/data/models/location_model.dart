/// Country, State and District models for onboarding location picker.

class CountryModel {
  final int    id;
  final String name;
  final String iso2;

  const CountryModel({required this.id, required this.name, required this.iso2});

  factory CountryModel.fromJson(Map<String, dynamic> j) => CountryModel(
    id:   int.tryParse(j['id'].toString()) ?? 0,
    name: j['name'] as String? ?? '',
    iso2: j['iso2'] as String? ?? '',
  );

  @override String toString() => name;
  @override bool operator ==(Object o) => o is CountryModel && o.id == id;
  @override int get hashCode => id.hashCode;
}

class StateModel {
  final int    id;
  final String name;
  final int    countryId;

  const StateModel({required this.id, required this.name, required this.countryId});

  factory StateModel.fromJson(Map<String, dynamic> j) => StateModel(
    id:        int.tryParse(j['id'].toString()) ?? 0,
    name:      j['name']       as String? ?? '',
    countryId: int.tryParse((j['country_id'] ?? '0').toString()) ?? 0,
  );

  @override String toString() => name;
  @override bool operator ==(Object o) => o is StateModel && o.id == id;
  @override int get hashCode => id.hashCode;
}

class DistrictModel {
  final int    id;
  final String name;
  final int    stateId;

  const DistrictModel({required this.id, required this.name, required this.stateId});

  factory DistrictModel.fromJson(Map<String, dynamic> j) => DistrictModel(
    id:      int.tryParse(j['id'].toString()) ?? 0,
    name:    j['name']     as String? ?? '',
    stateId: int.tryParse((j['state_id'] ?? '0').toString()) ?? 0,
  );

  @override String toString() => name;
  @override bool operator ==(Object o) => o is DistrictModel && o.id == id;
  @override int get hashCode => id.hashCode;
}

class LanguageModel {
  final int    id;
  final String name;
  final String code;
  final String nativeName;
  final String script;
  final String direction; // 'ltr' or 'rtl'

  const LanguageModel({
    required this.id,
    required this.name,
    required this.code,
    this.nativeName = '',
    this.script     = '',
    this.direction  = 'ltr',
  });

  bool get isRtl => direction == 'rtl';

  factory LanguageModel.fromJson(Map<String, dynamic> j) => LanguageModel(
    id:         int.tryParse(j['id'].toString()) ?? 0,
    name:       j['name']        as String? ?? '',
    code:       j['code']        as String? ?? '',
    nativeName: j['native_name'] as String? ?? '',
    script:     j['script']      as String? ?? '',
    direction:  j['direction']   as String? ?? 'ltr',
  );

  @override String toString() => name;
  @override bool operator ==(Object o) => o is LanguageModel && o.code == code;
  @override int get hashCode => code.hashCode;
}
