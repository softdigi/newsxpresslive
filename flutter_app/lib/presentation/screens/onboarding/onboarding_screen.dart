import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../providers/auth_provider.dart';
import '../../../providers/onboarding_provider.dart';
import '../../../providers/language_provider.dart';
import '../../../data/models/location_model.dart';
import '../../../data/models/category.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../language_selection/language_selection_screen.dart';

/// 3-step onboarding screen:
///   Step 1 — Select Location (Country → State → District)
///   Step 2 — Select Languages (Primary + Secondary)
///   Step 3 — Select Interests (categories multi-select)
///
/// After [saveAndComplete], the caller's [onComplete] callback is invoked
/// so the app navigates to [MainNavigation].
class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key, required this.onComplete});

  final VoidCallback onComplete;

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _pageController = PageController();
  int _currentPage = 0;
  static const int _totalSteps = 3;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final ob = context.read<OnboardingProvider>();
      ob.loadCountries();
      ob.loadLanguages();
      // Provide categories from news provider if available
      final cats = _getCategories();
      if (cats.isNotEmpty) ob.setAllCategories(cats);
    });
  }

  List<Category> _getCategories() {
    try {
      // Categories may be available from NewsProvider (already loaded on home)
      return [];
    } catch (_) {
      return [];
    }
  }

  @override
  void dispose() {
    _pageController.dispose();
    super.dispose();
  }

  void _nextPage() {
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

  Future<void> _finish() async {
    final auth = context.read<AuthProvider>();
    final uid  = auth.user?.firebaseUid;

    // Save language preferences locally (and to backend if authenticated)
    final langProv = context.read<LanguageProvider>();
    await langProv.save();

    if (uid == null) {
      widget.onComplete();
      return;
    }
    final success =
        await context.read<OnboardingProvider>().saveAndComplete(uid);
    if (success && mounted) {
      widget.onComplete();
    }
  }

  @override
  Widget build(BuildContext context) {
    final ob = context.watch<OnboardingProvider>();

    return Scaffold(
      appBar: AppBar(
        title: Text(AppStrings.onboardingTitle),
        leading: _currentPage > 0
            ? IconButton(
                icon: const Icon(Icons.arrow_back_rounded),
                onPressed: _prevPage,
              )
            : null,
        actions: [
          TextButton(
            onPressed: widget.onComplete,
            child: Text(AppStrings.skip,
                style: const TextStyle(color: AppColors.primary)),
          ),
        ],
      ),
      body: Column(
        children: [
          // Step indicator
          _StepIndicator(current: _currentPage, total: _totalSteps),

          // Pages
          Expanded(
            child: PageView(
              controller: _pageController,
              physics: const NeverScrollableScrollPhysics(),
              children: const [
                _LocationStep(),
                _LanguagesStep(),
                _InterestsStep(),
              ],
            ),
          ),

          // Bottom buttons
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
            child: SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: ob.isSaving
                    ? null
                    : (_currentPage < _totalSteps - 1
                        ? _nextPage
                        : _finish),
                child: ob.isSaving
                    ? const SizedBox(
                        height: 18,
                        width:  18,
                        child: CircularProgressIndicator(
                            color: Colors.white, strokeWidth: 2))
                    : Text(_currentPage < _totalSteps - 1
                        ? AppStrings.next
                        : AppStrings.done),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Step indicator ────────────────────────────────────────────────────────

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
            duration: const Duration(milliseconds: 200),
            margin: const EdgeInsets.symmetric(horizontal: 4),
            width:  active ? 24 : 8,
            height: 8,
            decoration: BoxDecoration(
              color: active
                  ? AppColors.primary
                  : AppColors.primary.withOpacity(0.3),
              borderRadius: BorderRadius.circular(4),
            ),
          );
        }),
      ),
    );
  }
}

// ── Step 1: Location ──────────────────────────────────────────────────────

class _LocationStep extends StatelessWidget {
  const _LocationStep();

  @override
  Widget build(BuildContext context) {
    final ob = context.watch<OnboardingProvider>();

    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _StepHeader(
            icon:     Icons.location_on_rounded,
            title:    AppStrings.stepLocation,
            subtitle: 'Get news relevant to your region',
          ),

          // Country dropdown
          _DropdownField<CountryModel>(
            label:    AppStrings.selectCountry,
            value:    ob.selectedCountry,
            items:    ob.countries,
            onChanged: (c) => ob.selectCountry(c!),
          ),
          const SizedBox(height: 16),

          // State dropdown
          if (ob.states.isNotEmpty)
            _DropdownField<StateModel>(
              label:     AppStrings.selectState,
              value:     ob.selectedState,
              items:     ob.states,
              onChanged: (s) => ob.selectState(s!),
            ),
          if (ob.states.isNotEmpty) const SizedBox(height: 16),

          // District dropdown
          if (ob.districts.isNotEmpty)
            _DropdownField<DistrictModel>(
              label:     AppStrings.selectDistrict,
              value:     ob.selectedDistrict,
              items:     ob.districts,
              onChanged: (d) => ob.selectDistrict(d),
              hint: 'Optional',
            ),
        ],
      ),
    );
  }
}

// ── Step 2: Languages ─────────────────────────────────────────────────────

/// Embeds [LanguageSelectionScreen] (without its own save button) inside the
/// onboarding page view.  The LanguageProvider must be available in the tree.
class _LanguagesStep extends StatelessWidget {
  const _LanguagesStep();

  @override
  Widget build(BuildContext context) {
    return const LanguageSelectionScreen(
      showSaveButton: false,
      title:          'Choose Your Languages',
      subtitle:       'Aap in languages mein news padhenge',
    );
  }
}

// ── Step 3: Interests ─────────────────────────────────────────────────────

class _InterestsStep extends StatelessWidget {
  const _InterestsStep();

  @override
  Widget build(BuildContext context) {
    final ob = context.watch<OnboardingProvider>();

    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _StepHeader(
            icon:     Icons.interests_rounded,
            title:    AppStrings.stepInterests,
            subtitle: 'Pick categories you care about',
          ),

          if (ob.allCategories.isEmpty)
            const Center(child: CircularProgressIndicator(
                color: AppColors.primary))
          else
            Wrap(
              spacing: 8,
              runSpacing: 10,
              children: ob.allCategories.map((cat) {
                final selected = ob.selectedCategoryIds.contains(cat.id);
                return FilterChip(
                  label:     Text(cat.name),
                  selected:  selected,
                  selectedColor: AppColors.primary.withOpacity(0.15),
                  checkmarkColor: AppColors.primary,
                  onSelected: (_) => ob.toggleCategory(cat.id),
                );
              }).toList(),
            ),
        ],
      ),
    );
  }
}

// ── Reusable helpers ──────────────────────────────────────────────────────

class _StepHeader extends StatelessWidget {
  const _StepHeader({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  final IconData icon;
  final String   title;
  final String   subtitle;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 24, top: 8),
      child: Row(
        children: [
          Container(
            width: 44, height: 44,
            decoration: BoxDecoration(
              color:        AppColors.primary.withOpacity(0.1),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: AppColors.primary, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title,
                    style: const TextStyle(
                        fontWeight: FontWeight.w700, fontSize: 16)),
                Text(subtitle,
                    style: const TextStyle(fontSize: 12, color: Colors.grey)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _DropdownField<T> extends StatelessWidget {
  const _DropdownField({
    required this.label,
    required this.value,
    required this.items,
    required this.onChanged,
    this.hint,
  });

  final String   label;
  final T?       value;
  final List<T>  items;
  final void Function(T?) onChanged;
  final String?  hint;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 6),
        DropdownButtonFormField<T>(
          value: value,
          hint: Text(hint ?? label),
          decoration: const InputDecoration(),
          isExpanded: true,
          items: items
              .map((item) => DropdownMenuItem<T>(
                    value: item,
                    child: Text(item.toString()),
                  ))
              .toList(),
          onChanged: onChanged,
        ),
      ],
    );
  }
}
