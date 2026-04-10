import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../data/models/mandi_model.dart';
import '../../data/services/mandi_service.dart';
import '../../core/constants/app_colors.dart';

/// Screen: State → District → Mandi cascading picker.
/// Returns a map with {id, name} via Navigator.pop.
class MandiSelectScreen extends StatefulWidget {
  const MandiSelectScreen({super.key});

  @override
  State<MandiSelectScreen> createState() => _MandiSelectScreenState();
}

class _MandiSelectScreenState extends State<MandiSelectScreen> {
  final _svc        = MandiService();
  final _searchCtrl = TextEditingController();

  List<NearbyMandi> _nearby  = [];
  bool _loadingNearby = false;
  String _query = '';

  // Hard-coded state list for demo. In production, fetch from geo/states.php.
  static const _states = [
    (9, 'Uttar Pradesh', 'उत्तर प्रदेश'),
    (5, 'Punjab', 'पंजाब'),
    (6, 'Haryana', 'हरियाणा'),
    (7, 'Madhya Pradesh', 'मध्य प्रदेश'),
    (8, 'Rajasthan', 'राजस्थान'),
  ];

  @override
  void initState() {
    super.initState();
    _loadNearby();
    _searchCtrl.addListener(() {
      setState(() => _query = _searchCtrl.text.trim().toLowerCase());
    });
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadNearby() async {
    // In production, use Geolocator to get real lat/lng.
    // Using Lucknow coords as fallback.
    setState(() => _loadingNearby = true);
    final list = await _svc.getNearby(lat: 26.846694, lng: 80.946166, radiusKm: 200);
    if (mounted) setState(() {
      _nearby       = list;
      _loadingNearby = false;
    });
  }

  void _selectMandi(int id, String nameHi) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt('mandi_fav_id', id);
    await prefs.setString('mandi_fav_name', nameHi);
    if (mounted) Navigator.pop(context, {'id': id, 'name': nameHi});
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('मंडी चुनें'),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              controller: _searchCtrl,
              decoration: InputDecoration(
                hintText: 'मंडी का नाम खोजें…',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: _query.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear),
                        onPressed: () => _searchCtrl.clear(),
                      )
                    : null,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ),
          Expanded(
            child: _query.isNotEmpty
                ? _buildSearchResults()
                : _buildNearbyAndStateList(),
          ),
        ],
      ),
    );
  }

  Widget _buildNearbyAndStateList() {
    return ListView(
      children: [
        // Nearby mandis
        if (_loadingNearby)
          const Padding(
            padding: EdgeInsets.all(16),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_nearby.isNotEmpty) ...[
          _sectionHeader('📍 आस-पास की मंडियां'),
          ..._nearby.map((m) => ListTile(
                leading: const Icon(Icons.store_outlined),
                title: Text(m.nameHi),
                subtitle: Text('${m.city ?? m.name} · ${m.distanceKm} km'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => _selectMandi(m.id, m.nameHi),
              )),
          const Divider(),
        ],
        // State list
        _sectionHeader('🗺️ राज्य के अनुसार चुनें'),
        ..._states.map((s) => ListTile(
              leading: const Icon(Icons.location_city_outlined),
              title: Text(s.$3),
              subtitle: Text(s.$2),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => _openStateMandiList(s.$1, s.$3),
            )),
      ],
    );
  }

  Widget _buildSearchResults() {
    final filtered = _nearby.where(
      (m) => m.name.toLowerCase().contains(_query) ||
             m.nameHi.contains(_query) ||
             (m.city ?? '').toLowerCase().contains(_query),
    ).toList();

    if (filtered.isEmpty) {
      return const Center(child: Text('कोई मंडी नहीं मिली'));
    }
    return ListView.builder(
      itemCount: filtered.length,
      itemBuilder: (_, i) {
        final m = filtered[i];
        return ListTile(
          leading: const Icon(Icons.store_outlined),
          title: Text(m.nameHi),
          subtitle: Text('${m.city ?? m.name} · ${m.distanceKm} km'),
          onTap: () => _selectMandi(m.id, m.nameHi),
        );
      },
    );
  }

  void _openStateMandiList(int stateId, String stateName) {
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => _StateMandiListScreen(
          stateId:   stateId,
          stateName: stateName,
          onSelect:  _selectMandi,
        ),
      ),
    );
  }

  Widget _sectionHeader(String title) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
        child: Text(
          title,
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
        ),
      );
}

// ── State-level mandi list ───────────────────────────────────────────────────

class _StateMandiListScreen extends StatelessWidget {
  const _StateMandiListScreen({
    required this.stateId,
    required this.stateName,
    required this.onSelect,
  });

  final int      stateId;
  final String   stateName;
  final void Function(int id, String name) onSelect;

  // Sample mandi data per state. In production replace with API call.
  static const _sampleMandis = <int, List<(int, String, String)>>{
    9: [
      (1, 'Lucknow Mandi',   'लखनऊ मंडी'),
      (2, 'Kanpur Mandi',    'कानपुर मंडी'),
      (3, 'Agra Mandi',      'आगरा मंडी'),
      (4, 'Varanasi Mandi',  'वाराणसी मंडी'),
      (5, 'Allahabad Mandi', 'प्रयागराज मंडी'),
      (6, 'Meerut Mandi',    'मेरठ मंडी'),
      (7, 'Gorakhpur Mandi', 'गोरखपुर मंडी'),
      (8, 'Bareilly Mandi',  'बरेली मंडी'),
    ],
  };

  @override
  Widget build(BuildContext context) {
    final mandis = _sampleMandis[stateId] ?? [];
    return Scaffold(
      appBar: AppBar(
        title: Text(stateName),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
      ),
      body: mandis.isEmpty
          ? const Center(child: Text('इस राज्य में कोई मंडी नहीं'))
          : ListView.builder(
              itemCount: mandis.length,
              itemBuilder: (_, i) => ListTile(
                leading: const Icon(Icons.store_outlined),
                title: Text(mandis[i].$3),
                subtitle: Text(mandis[i].$2),
                onTap: () => onSelect(mandis[i].$1, mandis[i].$3),
              ),
            ),
    );
  }
}
