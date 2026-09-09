import 'package:flutter/material.dart';
import '../../core/constants/app_colors.dart';
import '../../data/models/category.dart';

/// Horizontally scrollable category filter chips.
class CategoryChips extends StatelessWidget {
  const CategoryChips({
    super.key,
    required this.categories,
    required this.selected,
    required this.onSelect,
  });

  final List<Category> categories;
  final Category?      selected;
  final void Function(Category?) onSelect;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 40,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        itemCount: categories.length + 1, // +1 for "All"
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemBuilder: (context, index) {
          if (index == 0) {
            return _chip(
              label:    'All',
              selected: selected == null,
              onTap:    () => onSelect(null),
            );
          }
          final cat = categories[index - 1];
          return _chip(
            label:    cat.name,
            selected: selected?.id == cat.id,
            onTap:    () => onSelect(cat),
          );
        },
      ),
    );
  }

  Widget _chip({
    required String  label,
    required bool    selected,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
        decoration: BoxDecoration(
          color:        selected ? AppColors.primary : Colors.transparent,
          borderRadius: BorderRadius.circular(20),
          border:       Border.all(
            color: selected ? AppColors.primary : AppColors.divider,
          ),
        ),
        child: Text(
          label,
          style: TextStyle(
            color:      selected ? Colors.white : Colors.grey.shade700,
            fontSize:   13,
            fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
          ),
        ),
      ),
    );
  }
}
