import 'dart:async';
import 'package:flutter/material.dart';
import '../data/models/mood_category.dart';
import '../data/models/popup_decision.dart';
import '../data/services/preference_service.dart';
import '../data/services/api_service.dart';
import '../core/constants/app_colors.dart';

// ─────────────────────────────────────────────────────────────────────────────
// DailyMoodPopup
//
// Usage:
//   showModalBottomSheet(
//     context:           context,
//     isScrollControlled: true,
//     backgroundColor:    Colors.transparent,
//     builder: (_) => DailyMoodPopup(
//       decision:  popupDecision,
//       onSave:    (selectedSlugs) { /* refresh feed */ },
//     ),
//   );
// ─────────────────────────────────────────────────────────────────────────────

class DailyMoodPopup extends StatefulWidget {
  const DailyMoodPopup({
    super.key,
    required this.decision,
    required this.onSave,
  });

  /// The decoded response from `GET /api/preferences/check.php`.
  final PopupDecision decision;

  /// Called with the list of selected category slugs after a successful save.
  final void Function(List<String> selectedSlugs) onSave;

  // ── Convenience launcher ─────────────────────────────────────────────────

  /// Show the popup as a modal bottom sheet.
  static Future<void> show({
    required BuildContext      context,
    required PopupDecision     decision,
    required void Function(List<String>) onSave,
  }) {
    return showModalBottomSheet<void>(
      context:            context,
      isScrollControlled: true,
      backgroundColor:    Colors.transparent,
      builder:            (_) => DailyMoodPopup(
        decision: decision,
        onSave:   onSave,
      ),
    );
  }

  @override
  State<DailyMoodPopup> createState() => _DailyMoodPopupState();
}

class _DailyMoodPopupState extends State<DailyMoodPopup>
    with SingleTickerProviderStateMixin {
  // ── State ──────────────────────────────────────────────────────────────────
  late List<MoodCategory> _allCategories;
  late Set<String>        _selectedSlugs;

  bool _saving  = false;
  bool _skipping = false;

  late final DateTime _openedAt;

  late final PreferenceService _service;

  // ── Staggered animation ────────────────────────────────────────────────────
  final List<bool> _cardVisible = [];

  @override
  void initState() {
    super.initState();
    _openedAt = DateTime.now();

    final api = ApiService();
    _service  = PreferenceService(api: api);

    // Build the mutable category list, marking currently-selected ones
    final decision = widget.decision;
    _selectedSlugs = Set<String>.from(decision.currentPreferences);

    _allCategories = decision.allCategories.map((c) => c.copyWith(
          isCurrentlySelected: _selectedSlugs.contains(c.slug),
        )).toList();

    _cardVisible.addAll(List.filled(_allCategories.length, false));
    _startStaggeredAnimation();
  }

  void _startStaggeredAnimation() {
    for (var i = 0; i < _allCategories.length; i++) {
      final index = i;
      Timer(Duration(milliseconds: 50 * index), () {
        if (mounted) setState(() => _cardVisible[index] = true);
      });
    }
  }

  // ── Helpers ────────────────────────────────────────────────────────────────

  void _toggleCategory(String slug) {
    setState(() {
      if (_selectedSlugs.contains(slug)) {
        _selectedSlugs.remove(slug);
      } else {
        _selectedSlugs.add(slug);
      }
    });
  }

  String _greetingEmoji() {
    switch (widget.decision.context) {
      case 'morning':
      case 'morning_brief':
        return '🌅';
      case 'evening':
      case 'night_digest':
        return '🌆';
      case 'breaking_news':
        return '🔴';
      case 'afternoon_update':
        return '☀️';
      default:
        return '📰';
    }
  }

  String _greetingTitle() {
    switch (widget.decision.context) {
      case 'morning':
      case 'morning_brief':
        return 'Subah ki shuruaat!';
      case 'evening':
      case 'night_digest':
        return 'Shaam ka update';
      case 'breaking_news':
        return 'Breaking news aaya!';
      case 'afternoon_update':
        return 'Dopahar update';
      default:
        return 'Aaj ka news';
    }
  }

  MoodCategory? get _topCategory {
    if (_allCategories.isEmpty) return null;
    return _allCategories.reduce(
        (a, b) => a.behaviorScore >= b.behaviorScore ? a : b);
  }

  // ── Save ───────────────────────────────────────────────────────────────────

  Future<void> _onSave() async {
    if (_selectedSlugs.isEmpty || _saving) return;
    setState(() => _saving = true);

    final elapsed = DateTime.now().difference(_openedAt).inSeconds;

    final ok = await _service.saveMood(
      categories:           _selectedSlugs.toList(),
      context:              widget.decision.context,
      wasPopup:             true,
      timeToSelectSeconds:  elapsed,
    );

    if (!mounted) return;
    setState(() => _saving = false);

    if (ok) {
      Navigator.of(context).pop();
      widget.onSave(_selectedSlugs.toList());
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Save nahi ho saka, dobara try karein')),
      );
    }
  }

  // ── Skip ───────────────────────────────────────────────────────────────────

  Future<void> _onSkip() async {
    if (_skipping) return;
    setState(() => _skipping = true);

    await _service.skipPopup(
      context:   widget.decision.context,
      popupType: widget.decision.popupType,
    );

    if (mounted) {
      setState(() => _skipping = false);
      Navigator.of(context).pop();
    }
  }

  // ── Build ──────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final height = MediaQuery.of(context).size.height * 0.65;
    final top    = _topCategory;

    return Container(
      height: height,
      decoration: BoxDecoration(
        color: Theme.of(context).scaffoldBackgroundColor,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
      ),
      child: Column(
        children: [
          // Drag handle
          Container(
            margin: const EdgeInsets.only(top: 10),
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color:        Colors.grey.shade300,
              borderRadius: BorderRadius.circular(2),
            ),
          ),

          // ── Header ────────────────────────────────────────────────────
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 16, 8, 0),
            child: Row(
              children: [
                Text(
                  _greetingEmoji(),
                  style: const TextStyle(fontSize: 24),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _greetingTitle(),
                        style: const TextStyle(
                          fontSize:   17,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const Text(
                        'Aaj kya padhna chahte hain?',
                        style: TextStyle(fontSize: 12, color: Colors.grey),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon:      const Icon(Icons.close_rounded),
                  onPressed: _onSkip,
                  color:     Colors.grey,
                ),
              ],
            ),
          ),

          // ── Smart suggestions ─────────────────────────────────────────
          if (widget.decision.suggestedCategories.isNotEmpty)
            _SuggestionsRow(
              suggestions:   widget.decision.suggestedCategories,
              selectedSlugs: _selectedSlugs,
              onToggle:      _toggleCategory,
            ),

          // ── Categories grid ───────────────────────────────────────────
          Expanded(
            child: _CategoriesGrid(
              categories:   _allCategories,
              selectedSlugs: _selectedSlugs,
              cardVisible:   _cardVisible,
              topCategory:   top,
              onToggle:      _toggleCategory,
            ),
          ),

          // ── Action buttons ────────────────────────────────────────────
          _ActionBar(
            canSave:    _selectedSlugs.isNotEmpty,
            saving:     _saving,
            skipping:   _skipping,
            onSave:     _onSave,
            onSkip:     _onSkip,
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Smart Suggestions Row
// ─────────────────────────────────────────────────────────────────────────────

class _SuggestionsRow extends StatelessWidget {
  const _SuggestionsRow({
    required this.suggestions,
    required this.selectedSlugs,
    required this.onToggle,
  });

  final List<MoodCategory>         suggestions;
  final Set<String>                selectedSlugs;
  final void Function(String slug) onToggle;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Aaj ke liye suggest:',
            style: TextStyle(fontSize: 11, color: Colors.grey,
                fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: suggestions.take(3).map((cat) {
                final selected = selectedSlugs.contains(cat.slug);
                return GestureDetector(
                  onTap: () => onToggle(cat.slug),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    margin: const EdgeInsets.only(right: 8),
                    padding: const EdgeInsets.symmetric(
                        horizontal: 12, vertical: 6),
                    decoration: BoxDecoration(
                      color: selected
                          ? cat.color.withOpacity(0.15)
                          : Colors.grey.shade100,
                      border: Border.all(
                        color: selected ? cat.color : Colors.transparent,
                        width: 1.5,
                      ),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        if (cat.emoji.isNotEmpty)
                          Text(cat.emoji,
                              style: const TextStyle(fontSize: 14)),
                        if (cat.emoji.isNotEmpty) const SizedBox(width: 4),
                        Text(
                          cat.name,
                          style: TextStyle(
                            fontSize:   12,
                            fontWeight: FontWeight.w600,
                            color:      selected ? cat.color : null,
                          ),
                        ),
                        if (selected) ...[
                          const SizedBox(width: 4),
                          Icon(Icons.check, size: 12, color: cat.color),
                        ],
                      ],
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Categories Grid
// ─────────────────────────────────────────────────────────────────────────────

class _CategoriesGrid extends StatelessWidget {
  const _CategoriesGrid({
    required this.categories,
    required this.selectedSlugs,
    required this.cardVisible,
    required this.topCategory,
    required this.onToggle,
  });

  final List<MoodCategory>         categories;
  final Set<String>                selectedSlugs;
  final List<bool>                 cardVisible;
  final MoodCategory?              topCategory;
  final void Function(String slug) onToggle;

  @override
  Widget build(BuildContext context) {
    return GridView.builder(
      padding:  const EdgeInsets.fromLTRB(12, 12, 12, 4),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount:   2,
        crossAxisSpacing: 10,
        mainAxisSpacing:  10,
        childAspectRatio: 2.5,
      ),
      itemCount: categories.length,
      itemBuilder: (context, i) {
        final cat      = categories[i];
        final selected = selectedSlugs.contains(cat.slug);
        final isTop    = topCategory?.slug == cat.slug;
        final visible  = i < cardVisible.length ? cardVisible[i] : true;

        return AnimatedOpacity(
          duration: const Duration(milliseconds: 300),
          opacity:  visible ? 1.0 : 0.0,
          child: _CategoryCard(
            category:  cat,
            selected:  selected,
            isTop:     isTop,
            onTap:     () => onToggle(cat.slug),
          ),
        );
      },
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Individual Category Card with scale-bounce tap animation
// ─────────────────────────────────────────────────────────────────────────────

class _CategoryCard extends StatefulWidget {
  const _CategoryCard({
    required this.category,
    required this.selected,
    required this.isTop,
    required this.onTap,
  });

  final MoodCategory category;
  final bool         selected;
  final bool         isTop;
  final VoidCallback onTap;

  @override
  State<_CategoryCard> createState() => _CategoryCardState();
}

class _CategoryCardState extends State<_CategoryCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double>   _scale;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync:    this,
      duration: const Duration(milliseconds: 250),
    );
    _scale = TweenSequence<double>([
      TweenSequenceItem(
          tween: Tween(begin: 1.0, end: 0.95), weight: 40),
      TweenSequenceItem(
          tween: Tween(begin: 0.95, end: 1.05), weight: 30),
      TweenSequenceItem(
          tween: Tween(begin: 1.05, end: 1.0), weight: 30),
    ]).animate(_ctrl);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  Future<void> _handleTap() async {
    await _ctrl.forward(from: 0);
    widget.onTap();
  }

  @override
  Widget build(BuildContext context) {
    final cat   = widget.category;
    final color = cat.color;

    return ScaleTransition(
      scale: _scale,
      child: GestureDetector(
        onTap: _handleTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          decoration: BoxDecoration(
            color:        color.withOpacity(0.1),
            borderRadius: BorderRadius.circular(10),
            border:       Border.all(
              color: widget.selected ? color : Colors.transparent,
              width: 2,
            ),
          ),
          child: Stack(
            children: [
              // "Aapka favorite" badge (top-left)
              if (widget.isTop)
                Positioned(
                  top: 0, left: 0,
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color:        color,
                      borderRadius: const BorderRadius.only(
                        topLeft:     Radius.circular(8),
                        bottomRight: Radius.circular(8),
                      ),
                    ),
                    child: const Text(
                      'Aapka favorite',
                      style: TextStyle(
                          color:      Colors.white,
                          fontSize:   8,
                          fontWeight: FontWeight.w700),
                    ),
                  ),
                ),

              // Card body
              Padding(
                padding: const EdgeInsets.symmetric(
                    horizontal: 10, vertical: 8),
                child: Row(
                  children: [
                    Text(
                      cat.emoji.isNotEmpty ? cat.emoji : '📰',
                      style: const TextStyle(fontSize: 20),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisAlignment:  MainAxisAlignment.center,
                        children: [
                          Text(
                            cat.name,
                            maxLines:  1,
                            overflow:  TextOverflow.ellipsis,
                            style: const TextStyle(
                                fontSize:   13,
                                fontWeight: FontWeight.w600),
                          ),
                          if (cat.newArticlesCount > 0)
                            Text(
                              '${cat.newArticlesCount} naye articles',
                              style: const TextStyle(
                                  fontSize: 10,
                                  color:    Colors.grey),
                            ),
                        ],
                      ),
                    ),
                    if (widget.selected)
                      Icon(Icons.check_circle_rounded,
                          size: 18, color: color),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Action Buttons Bar
// ─────────────────────────────────────────────────────────────────────────────

class _ActionBar extends StatelessWidget {
  const _ActionBar({
    required this.canSave,
    required this.saving,
    required this.skipping,
    required this.onSave,
    required this.onSkip,
  });

  final bool         canSave;
  final bool         saving;
  final bool         skipping;
  final VoidCallback onSave;
  final VoidCallback onSkip;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
          16, 8, 16, MediaQuery.of(context).padding.bottom + 12),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              onPressed: (saving || !canSave) ? null : onSave,
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: Colors.white,
                padding:
                    const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
              child: saving
                  ? const SizedBox(
                      width:  18,
                      height: 18,
                      child: CircularProgressIndicator(
                          color: Colors.white, strokeWidth: 2))
                  : const Text(
                      'Aaj ke liye save karo',
                      style: TextStyle(
                          fontSize:   15,
                          fontWeight: FontWeight.w700),
                    ),
            ),
          ),
          const SizedBox(height: 4),
          TextButton(
            onPressed: (skipping || saving) ? null : onSkip,
            child: skipping
                ? const SizedBox(
                    width:  14,
                    height: 14,
                    child: CircularProgressIndicator(
                        color: Colors.grey, strokeWidth: 2))
                : const Text(
                    'Skip',
                    style: TextStyle(
                        color:    Colors.grey,
                        fontSize: 13),
                  ),
          ),
        ],
      ),
    );
  }
}
