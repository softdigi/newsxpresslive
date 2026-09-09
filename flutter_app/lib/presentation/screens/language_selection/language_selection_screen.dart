import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../providers/language_provider.dart';
import '../../../data/models/location_model.dart';
import '../../../core/constants/app_colors.dart';

/// Grid-based multi-select language picker.
///
/// Shows all supported languages with their native names.
/// Used both in onboarding and from Settings.
///
/// Parameters:
///   [onSaved]   — called after preferences are saved (optional).
///   [showSaveButton] — whether to show the save/continue button.
class LanguageSelectionScreen extends StatefulWidget {
  const LanguageSelectionScreen({
    super.key,
    this.onSaved,
    this.showSaveButton = true,
    this.title          = 'Choose Your Languages',
    this.subtitle       = 'Aap in languages mein news padhenge',
  });

  final VoidCallback? onSaved;
  final bool          showSaveButton;
  final String        title;
  final String        subtitle;

  @override
  State<LanguageSelectionScreen> createState() =>
      _LanguageSelectionScreenState();
}

class _LanguageSelectionScreenState extends State<LanguageSelectionScreen> {
  final _searchCtrl = TextEditingController();
  String _query     = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final prov = context.read<LanguageProvider>();
      if (prov.supported.isEmpty) prov.loadSupported();
    });
    _searchCtrl.addListener(() {
      setState(() => _query = _searchCtrl.text.toLowerCase().trim());
    });
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  List<LanguageModel> _filtered(List<LanguageModel> all) {
    if (_query.isEmpty) return all;
    return all.where((l) {
      return l.name.toLowerCase().contains(_query) ||
          l.nativeName.toLowerCase().contains(_query) ||
          l.code.toLowerCase().contains(_query);
    }).toList();
  }

  Future<void> _save() async {
    final prov    = context.read<LanguageProvider>();
    final success = await prov.save();
    if (success && mounted) {
      widget.onSaved?.call();
      if (Navigator.canPop(context)) Navigator.pop(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    final prov = context.watch<LanguageProvider>();

    return Directionality(
      // Language selection screen is always LTR regardless of app language,
      // so users can read all language names comfortably in a consistent grid.
      textDirection: TextDirection.ltr,
      child: Scaffold(
        appBar: AppBar(
          title: Text(widget.title),
          actions: [
            if (prov.loadState == LanguageLoadState.saving)
              const Padding(
                padding: EdgeInsets.only(right: 16),
                child: Center(
                  child: SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: AppColors.primary,
                    ),
                  ),
                ),
              ),
          ],
        ),
        body: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Header hint
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: Text(
                widget.subtitle,
                style: Theme.of(context)
                    .textTheme
                    .bodyMedium
                    ?.copyWith(color: Colors.grey[600]),
              ),
            ),

            // Search box
            Padding(
              padding: const EdgeInsets.all(12),
              child: TextField(
                controller: _searchCtrl,
                decoration: InputDecoration(
                  hintText:     'Search languages...',
                  prefixIcon:   const Icon(Icons.search_rounded),
                  border:       OutlineInputBorder(
                      borderRadius: BorderRadius.circular(12)),
                  contentPadding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  suffixIcon: _query.isNotEmpty
                      ? IconButton(
                          icon: const Icon(Icons.clear_rounded),
                          onPressed: () => _searchCtrl.clear(),
                        )
                      : null,
                ),
              ),
            ),

            // Grid
            Expanded(
              child: _buildGrid(prov),
            ),

            // Selection counter + save button
            if (widget.showSaveButton) _buildBottomBar(prov),
          ],
        ),
      ),
    );
  }

  Widget _buildGrid(LanguageProvider prov) {
    if (prov.loadState == LanguageLoadState.loading) {
      return const Center(child: CircularProgressIndicator(color: AppColors.primary));
    }

    if (prov.loadState == LanguageLoadState.error) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(prov.errorMsg ?? 'Error loading languages'),
            const SizedBox(height: 12),
            ElevatedButton(
              onPressed: prov.loadSupported,
              child: const Text('Retry'),
            ),
          ],
        ),
      );
    }

    final items = _filtered(prov.supported);
    if (items.isEmpty) {
      return const Center(child: Text('No languages found'));
    }

    return GridView.builder(
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount:    3,
        crossAxisSpacing:  8,
        mainAxisSpacing:   8,
        childAspectRatio:  1.6,
      ),
      itemCount: items.length,
      itemBuilder: (_, i) => _LanguageChip(
        language:   items[i],
        isSelected: prov.isSelected(items[i].code),
        onTap:      () => prov.toggleCode(items[i].code),
      ),
    );
  }

  Widget _buildBottomBar(LanguageProvider prov) {
    final count = prov.selected.length;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
        child: Row(
          children: [
            Expanded(
              child: Text(
                '$count language${count == 1 ? '' : 's'} selected',
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
            ElevatedButton(
              onPressed: count >= 1 && prov.loadState != LanguageLoadState.saving
                  ? _save
                  : null,
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(8)),
              ),
              child: Text(widget.onSaved != null ? 'Continue' : 'Save'),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Language chip ─────────────────────────────────────────────────────────────

class _LanguageChip extends StatelessWidget {
  const _LanguageChip({
    required this.language,
    required this.isSelected,
    required this.onTap,
  });

  final LanguageModel language;
  final bool          isSelected;
  final VoidCallback  onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        decoration: BoxDecoration(
          color:        isSelected ? AppColors.primary : Colors.transparent,
          border:       Border.all(
            color: isSelected ? AppColors.primary : Colors.grey.shade400,
            width: isSelected ? 2 : 1,
          ),
          borderRadius: BorderRadius.circular(12),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Wrap native name in correct directionality
            Directionality(
              textDirection: language.isRtl
                  ? TextDirection.rtl
                  : TextDirection.ltr,
              child: Text(
                language.nativeName.isNotEmpty
                    ? language.nativeName
                    : language.name,
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontWeight: FontWeight.w700,
                  fontSize:   14,
                  color:      isSelected ? Colors.white : null,
                ),
              ),
            ),
            if (language.nativeName.isNotEmpty &&
                language.nativeName != language.name)
              Text(
                language.name,
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: 11,
                  color:    isSelected ? Colors.white70 : Colors.grey[600],
                ),
              ),
          ],
        ),
      ),
    );
  }
}
