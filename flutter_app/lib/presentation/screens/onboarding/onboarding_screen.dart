import 'package:flutter/material.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import '../../../data/models/mood_category.dart';
import '../../../data/services/preference_service.dart';
import '../../../data/services/api_service.dart';
import '../../../data/services/onboarding_service.dart';
import '../../../data/models/location_model.dart';
import '../../../core/constants/app_colors.dart';

// ─────────────────────────────────────────────────────────────────────────────
// OnboardingScreen — 4-step mandatory onboarding
//   Step 1: Language selection
//   Step 2: Location (state + district; skip allowed)
//   Step 3: Categories (min 3 required; 3-column grid)
//   Step 4: Notifications permission
// ─────────────────────────────────────────────────────────────────────────────

class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key, required this.onComplete});

  final VoidCallback onComplete;

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _pageController = PageController();
  int _currentPage      = 0;
  static const int _totalSteps = 4;

  bool _saving = false;

  // ── Services ─────────────────────────────────────────────────────────────
  late final PreferenceService  _prefService;
  late final OnboardingService  _obService;

  // ── Step 1: Language ──────────────────────────────────────────────────────
  static const _availableLanguages = [
    {'code': 'hi',  'label': 'हिंदी'},
    {'code': 'en',  'label': 'English'},
    {'code': 'ur',  'label': 'اردو'},
    {'code': 'mr',  'label': 'मराठी'},
    {'code': 'bn',  'label': 'বাংলা'},
    {'code': 'te',  'label': 'తెలుగు'},
  ];
  final Set<String> _selectedLanguages = {'hi'}; // default: Hindi

  // ── Step 2: Location ──────────────────────────────────────────────────────
  List<StateModel>    _states    = [];
  List<DistrictModel> _districts = [];
  StateModel?    _selectedState;
  DistrictModel? _selectedDistrict;
  bool _loadingStates    = false;
  bool _loadingDistricts = false;
  bool _locationSkipped  = false;

  // ── Step 3: Categories ────────────────────────────────────────────────────
  List<MoodCategory> _categories          = [];
  final Set<String>  _selectedCategorySlugs = {};
  bool _loadingCats = false;

  // ── Step 4: Notifications ─────────────────────────────────────────────────
  bool _notificationHandled = false;

  // ─────────────────────────────────────────────────────────────────────────

  @override
  void initState() {
    super.initState();
    final api = ApiService();
    _prefService = PreferenceService(api: api);
    _obService   = OnboardingService(api: api);
    _loadStates();
    _loadCategories();
  }

  @override
  void dispose() {
    _pageController.dispose();
    super.dispose();
  }

  // ── Data loaders ──────────────────────────────────────────────────────────

  Future<void> _loadStates() async {
    setState(() => _loadingStates = true);
    try {
      // India = country id 1 (default)
      _states = await _obService.getStates(1);
    } catch (_) {}
    if (mounted) setState(() => _loadingStates = false);
  }

  Future<void> _loadDistricts(int stateId) async {
    setState(() {
      _loadingDistricts = true;
      _districts        = [];
      _selectedDistrict = null;
    });
    try {
      _districts = await _obService.getDistricts(stateId);
    } catch (_) {}
    if (mounted) setState(() => _loadingDistricts = false);
  }

  Future<void> _loadCategories() async {
    setState(() => _loadingCats = true);
    try {
      _categories = await _prefService.getMoodCategories();
    } catch (_) {}
    if (mounted) setState(() => _loadingCats = false);
  }

  // ── Navigation ────────────────────────────────────────────────────────────

  void _nextPage() {
    if (_saving) return;
    if (_currentPage < _totalSteps - 1) {
      _pageController.nextPage(
        duration: const Duration(milliseconds: 300),
        curve:    Curves.easeInOut,
      );
      setState(() => _currentPage++);
    }
  }

  void _prevPage() {
    if (_currentPage > 0) {
      _pageController.previousPage(
        duration: const Duration(milliseconds: 300),
        curve:    Curves.easeInOut,
      );
      setState(() => _currentPage--);
    }
  }

  // ── Step submissions ──────────────────────────────────────────────────────

  Future<void> _submitLanguageStep() async {
    if (_selectedLanguages.isEmpty) return;
    setState(() => _saving = true);
    await _prefService.saveOnboardingStep(
      step: 'language',
      data: {'languages': _selectedLanguages.toList()},
    );
    if (mounted) {
      setState(() => _saving = false);
      _nextPage();
    }
  }

  Future<void> _submitLocationStep({bool skip = false}) async {
    setState(() => _saving = true);
    if (!skip && _selectedState != null) {
      await _prefService.saveOnboardingStep(
        step: 'location',
        data: {
          'state_id':    _selectedState!.id,
          if (_selectedDistrict != null) 'district_id': _selectedDistrict!.id,
        },
      );
    }
    _locationSkipped = skip;
    if (mounted) {
      setState(() => _saving = false);
      _nextPage();
    }
  }

  Future<void> _submitCategoriesStep() async {
    if (_selectedCategorySlugs.length < 3) return;
    setState(() => _saving = true);
    await _prefService.saveOnboardingStep(
      step: 'categories',
      data: {'categories': _selectedCategorySlugs.toList()},
    );
    if (mounted) {
      setState(() => _saving = false);
      _nextPage();
    }
  }

  Future<void> _submitNotificationsStep({required bool allowed}) async {
    if (_notificationHandled) return;
    _notificationHandled = true;
    setState(() => _saving = true);

    String? fcmToken;
    if (allowed) {
      try {
        await FirebaseMessaging.instance.requestPermission(
          alert: true, badge: true, sound: true,
        );
        fcmToken = await FirebaseMessaging.instance.getToken();
      } catch (_) {}
    }

    await _prefService.saveOnboardingStep(
      step: 'notifications',
      data: {
        'notification_allowed': allowed,
        if (fcmToken != null) 'fcm_token': fcmToken,
      },
    );

    if (mounted) {
      setState(() => _saving = false);
      widget.onComplete();
    }
  }

  // ── Build ──────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      appBar: AppBar(
        elevation: 0,
        backgroundColor: Colors.transparent,
        leading: _currentPage > 0
            ? IconButton(
                icon: const Icon(Icons.arrow_back_rounded),
                onPressed: _saving ? null : _prevPage,
              )
            : null,
        automaticallyImplyLeading: false,
      ),
      body: SafeArea(
        child: Column(
          children: [
            _StepIndicator(current: _currentPage, total: _totalSteps),
            Expanded(
              child: PageView(
                controller:  _pageController,
                physics:     const NeverScrollableScrollPhysics(),
                children: [
                  _LanguageStep(
                    languages:         _availableLanguages,
                    selectedLanguages:  _selectedLanguages,
                    onToggle: (code) => setState(
                        () => _selectedLanguages.contains(code)
                            ? (_selectedLanguages.length > 1
                                ? _selectedLanguages.remove(code)
                                : null)
                            : _selectedLanguages.add(code)),
                  ),
                  _LocationStep(
                    states:           _states,
                    districts:        _districts,
                    selectedState:    _selectedState,
                    selectedDistrict: _selectedDistrict,
                    loadingStates:    _loadingStates,
                    loadingDistricts: _loadingDistricts,
                    onStateChanged: (s) {
                      setState(() => _selectedState = s);
                      if (s != null) _loadDistricts(s.id);
                    },
                    onDistrictChanged: (d) => setState(() => _selectedDistrict = d),
                  ),
                  _CategoriesStep(
                    categories:      _categories,
                    selectedSlugs:   _selectedCategorySlugs,
                    loading:         _loadingCats,
                    onToggle: (slug) => setState(() =>
                        _selectedCategorySlugs.contains(slug)
                            ? _selectedCategorySlugs.remove(slug)
                            : _selectedCategorySlugs.add(slug)),
                  ),
                  const _NotificationsStep(),
                ],
              ),
            ),
            _BottomBar(
              currentPage:     _currentPage,
              saving:          _saving,
              canAdvanceStep1: _selectedLanguages.isNotEmpty,
              canAdvanceStep3: _selectedCategorySlugs.length >= 3,
              onStep0Next:     _submitLanguageStep,
              onStep1Skip:     () => _submitLocationStep(skip: true),
              onStep1Next:     () => _submitLocationStep(),
              onStep2Next:     _submitCategoriesStep,
              onStep3Allow:    () => _submitNotificationsStep(allowed: true),
              onStep3Skip:     () => _submitNotificationsStep(allowed: false),
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Step indicator
// ─────────────────────────────────────────────────────────────────────────────

class _StepIndicator extends StatelessWidget {
  const _StepIndicator({required this.current, required this.total});

  final int current;
  final int total;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: List.generate(total, (i) {
          final active = i == current;
          return AnimatedContainer(
            duration: const Duration(milliseconds: 250),
            margin: const EdgeInsets.symmetric(horizontal: 4),
            width:  active ? 28 : 8,
            height: 8,
            decoration: BoxDecoration(
              color: active
                  ? AppColors.primary
                  : AppColors.primary.withOpacity(0.25),
              borderRadius: BorderRadius.circular(4),
            ),
          );
        }),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 1 — Language
// ─────────────────────────────────────────────────────────────────────────────

class _LanguageStep extends StatelessWidget {
  const _LanguageStep({
    required this.languages,
    required this.selectedLanguages,
    required this.onToggle,
  });

  final List<Map<String, String>> languages;
  final Set<String>               selectedLanguages;
  final void Function(String)     onToggle;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SizedBox(height: 8),
          const Text(
            'Aap kaunsi bhasha mein\nnews padhna chahte hain?',
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          const Text(
            'Ek ya adhik bhasha chunein',
            style: TextStyle(fontSize: 13, color: Colors.grey),
          ),
          const SizedBox(height: 28),
          Wrap(
            spacing:    12,
            runSpacing: 12,
            children: languages.map((lang) {
              final code     = lang['code']!;
              final label    = lang['label']!;
              final selected = selectedLanguages.contains(code);
              return GestureDetector(
                onTap: () => onToggle(code),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  padding: const EdgeInsets.symmetric(
                      horizontal: 20, vertical: 12),
                  decoration: BoxDecoration(
                    color: selected
                        ? AppColors.primary.withOpacity(0.12)
                        : Theme.of(context).cardColor,
                    border: Border.all(
                      color: selected
                          ? AppColors.primary
                          : Colors.grey.withOpacity(0.3),
                      width: selected ? 2 : 1,
                    ),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (selected) ...[
                        const Icon(Icons.check_circle,
                            size: 18, color: AppColors.primary),
                        const SizedBox(width: 6),
                      ],
                      Text(
                        label,
                        style: TextStyle(
                          fontSize:   15,
                          fontWeight: FontWeight.w600,
                          color:      selected
                              ? AppColors.primary
                              : null,
                        ),
                      ),
                    ],
                  ),
                ),
              );
            }).toList(),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 2 — Location
// ─────────────────────────────────────────────────────────────────────────────

class _LocationStep extends StatelessWidget {
  const _LocationStep({
    required this.states,
    required this.districts,
    required this.selectedState,
    required this.selectedDistrict,
    required this.loadingStates,
    required this.loadingDistricts,
    required this.onStateChanged,
    required this.onDistrictChanged,
  });

  final List<StateModel>    states;
  final List<DistrictModel> districts;
  final StateModel?         selectedState;
  final DistrictModel?      selectedDistrict;
  final bool                loadingStates;
  final bool                loadingDistricts;
  final void Function(StateModel?)    onStateChanged;
  final void Function(DistrictModel?) onDistrictChanged;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SizedBox(height: 8),
          const Text(
            'Aapka shehar kaunsa hai?',
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          const Text(
            'Apne kshetr ki khabrein paayein',
            style: TextStyle(fontSize: 13, color: Colors.grey),
          ),
          const SizedBox(height: 28),

          // State dropdown
          if (loadingStates)
            const Center(
                child: CircularProgressIndicator(color: AppColors.primary))
          else if (states.isNotEmpty) ...[
            const Text('Rajya (State)',
                style: TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 6),
            DropdownButtonFormField<StateModel>(
              value:    selectedState,
              hint:     const Text('Rajya chunein'),
              isExpanded: true,
              decoration: const InputDecoration(),
              items: states
                  .map((s) => DropdownMenuItem(value: s, child: Text(s.name)))
                  .toList(),
              onChanged: onStateChanged,
            ),
          ],

          const SizedBox(height: 16),

          // District dropdown (visible once state is selected)
          if (selectedState != null) ...[
            const Text('Zila (District)',
                style: TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 6),
            if (loadingDistricts)
              const Center(
                  child: SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                          color: AppColors.primary, strokeWidth: 2)))
            else
              DropdownButtonFormField<DistrictModel>(
                value:    selectedDistrict,
                hint:     const Text('Zila chunein (optional)'),
                isExpanded: true,
                decoration: const InputDecoration(),
                items: districts
                    .map((d) =>
                        DropdownMenuItem(value: d, child: Text(d.name)))
                    .toList(),
                onChanged: onDistrictChanged,
              ),
          ],

          const SizedBox(height: 24),

          // GPS auto-detect
          OutlinedButton.icon(
            onPressed: () => ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text('GPS detection — device permission required'),
                duration: Duration(seconds: 2),
              ),
            ),
            icon:  const Icon(Icons.my_location_rounded, size: 18),
            label: const Text('GPS se auto-detect'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.primary,
              side: const BorderSide(color: AppColors.primary),
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 3 — Categories
// ─────────────────────────────────────────────────────────────────────────────

class _CategoriesStep extends StatelessWidget {
  const _CategoriesStep({
    required this.categories,
    required this.selectedSlugs,
    required this.loading,
    required this.onToggle,
  });

  final List<MoodCategory> categories;
  final Set<String>        selectedSlugs;
  final bool               loading;
  final void Function(String slug) onToggle;

  @override
  Widget build(BuildContext context) {
    final selectedCount = selectedSlugs.length;
    final total         = categories.length;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Aap kya padhna chahte hain?',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 6),
              Row(
                children: [
                  const Text(
                    'Kam se kam 3 topics chunein',
                    style: TextStyle(fontSize: 13, color: Colors.grey),
                  ),
                  const Spacer(),
                  // Counter badge
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: selectedCount >= 3
                          ? AppColors.primary
                          : Colors.grey.shade300,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(
                      '$selectedCount${total > 0 ? '/$total' : ''} selected',
                      style: TextStyle(
                        fontSize:   11,
                        fontWeight: FontWeight.w700,
                        color:      selectedCount >= 3
                            ? Colors.white
                            : Colors.grey.shade700,
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        if (loading)
          const Expanded(
              child: Center(
                  child: CircularProgressIndicator(color: AppColors.primary)))
        else
          Expanded(
            child: GridView.builder(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              gridDelegate:
                  const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount:    3,
                crossAxisSpacing:  10,
                mainAxisSpacing:   10,
                childAspectRatio:  0.9,
              ),
              itemCount: categories.length,
              itemBuilder: (context, i) {
                final cat      = categories[i];
                final selected = selectedSlugs.contains(cat.slug);
                final bgColor  = cat.color;

                return GestureDetector(
                  onTap: () => onToggle(cat.slug),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    decoration: BoxDecoration(
                      color:        bgColor.withOpacity(0.12),
                      borderRadius: BorderRadius.circular(12),
                      border:       Border.all(
                        color: selected
                            ? bgColor
                            : Colors.transparent,
                        width: 2,
                      ),
                    ),
                    child: Stack(
                      children: [
                        Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Text(
                                cat.emoji.isNotEmpty ? cat.emoji : '📰',
                                style: const TextStyle(fontSize: 28),
                              ),
                              const SizedBox(height: 6),
                              Text(
                                cat.name,
                                textAlign: TextAlign.center,
                                maxLines:  2,
                                overflow:  TextOverflow.ellipsis,
                                style: const TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.w600),
                              ),
                            ],
                          ),
                        ),
                        if (selected)
                          Positioned(
                            top: 6, right: 6,
                            child: Container(
                              width: 20, height: 20,
                              decoration: BoxDecoration(
                                color:  bgColor,
                                shape:  BoxShape.circle,
                              ),
                              child: const Icon(Icons.check,
                                  size: 13, color: Colors.white),
                            ),
                          ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 4 — Notifications
// ─────────────────────────────────────────────────────────────────────────────

class _NotificationsStep extends StatelessWidget {
  const _NotificationsStep();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 32),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            width: 80, height: 80,
            decoration: BoxDecoration(
              color:        AppColors.primary.withOpacity(0.12),
              borderRadius: BorderRadius.circular(20),
            ),
            child: const Icon(Icons.notifications_active_rounded,
                size: 40, color: AppColors.primary),
          ),
          const SizedBox(height: 24),
          const Text(
            'Breaking news\nmiss mat karo!',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 24, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 12),
          const Text(
            'Important khabrein turant paayein',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 14, color: Colors.grey),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Bottom action bar (step-aware)
// ─────────────────────────────────────────────────────────────────────────────

class _BottomBar extends StatelessWidget {
  const _BottomBar({
    required this.currentPage,
    required this.saving,
    required this.canAdvanceStep1,
    required this.canAdvanceStep3,
    required this.onStep0Next,
    required this.onStep1Skip,
    required this.onStep1Next,
    required this.onStep2Next,
    required this.onStep3Allow,
    required this.onStep3Skip,
  });

  final int  currentPage;
  final bool saving;
  final bool canAdvanceStep1;
  final bool canAdvanceStep3;
  final VoidCallback onStep0Next;
  final VoidCallback onStep1Skip;
  final VoidCallback onStep1Next;
  final VoidCallback onStep2Next;
  final VoidCallback onStep3Allow;
  final VoidCallback onStep3Skip;

  @override
  Widget build(BuildContext context) {
    final loading = saving
        ? const SizedBox(
            width: 18,
            height: 18,
            child: CircularProgressIndicator(
                color: Colors.white, strokeWidth: 2))
        : null;

    Widget content;

    switch (currentPage) {
      // ── Step 1: Language ──
      case 0:
        content = SizedBox(
          width: double.infinity,
          child: ElevatedButton(
            onPressed:
                (saving || !canAdvanceStep1) ? null : onStep0Next,
            child: loading ?? const Text('Aage badho →'),
          ),
        );

      // ── Step 2: Location ──
      case 1:
        content = Row(
          children: [
            Expanded(
              child: OutlinedButton(
                onPressed: saving ? null : onStep1Skip,
                child: const Text('Baad mein set karein'),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: ElevatedButton(
                onPressed: saving ? null : onStep1Next,
                child: loading ?? const Text('Aage badho →'),
              ),
            ),
          ],
        );

      // ── Step 3: Categories ──
      case 2:
        content = SizedBox(
          width: double.infinity,
          child: ElevatedButton(
            onPressed: (saving || !canAdvanceStep3) ? null : onStep2Next,
            child: loading ?? const Text('Aage badho →'),
          ),
        );

      // ── Step 4: Notifications ──
      default:
        content = Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: saving ? null : onStep3Allow,
                child: loading ??
                    const Text('Allow Notifications'),
              ),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: saving ? null : onStep3Skip,
              child: const Text(
                'Abhi nahi',
                style: TextStyle(color: Colors.grey, fontSize: 13),
              ),
            ),
          ],
        );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
      child: content,
    );
  }
}
