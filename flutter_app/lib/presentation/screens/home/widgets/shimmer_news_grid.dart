import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';
import '../../../core/constants/app_colors.dart';

/// Shimmer skeleton placeholder for the news grid.
/// Shown while the first page of articles is loading.
class ShimmerNewsGrid extends StatelessWidget {
  const ShimmerNewsGrid({super.key, this.itemCount = 6});

  final int itemCount;

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor:    AppColors.shimmerBase,
      highlightColor: AppColors.shimmerHighlight,
      child: GridView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        padding: const EdgeInsets.symmetric(horizontal: 12),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount:   2,
          mainAxisSpacing:  10,
          crossAxisSpacing: 10,
          childAspectRatio: 0.72,
        ),
        itemCount: itemCount,
        itemBuilder: (_, __) => const _ShimmerCard(),
      ),
    );
  }
}

/// Shimmer placeholder for a single horizontal (compact) card row.
class ShimmerCompactCard extends StatelessWidget {
  const ShimmerCompactCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor:    AppColors.shimmerBase,
      highlightColor: AppColors.shimmerHighlight,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(
          children: [
            _box(80, 60, radius: 8),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _box(double.infinity, 13),
                  const SizedBox(height: 6),
                  _box(double.infinity, 13),
                  const SizedBox(height: 6),
                  _box(80, 11),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Shimmer for the featured hero card.
class ShimmerFeaturedCard extends StatelessWidget {
  const ShimmerFeaturedCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor:    AppColors.shimmerBase,
      highlightColor: AppColors.shimmerHighlight,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _box(double.infinity, 200, radius: 12),
            const SizedBox(height: 10),
            _box(double.infinity, 18),
            const SizedBox(height: 6),
            _box(200, 18),
            const SizedBox(height: 6),
            _box(100, 12),
          ],
        ),
      ),
    );
  }
}

// ── Internals ─────────────────────────────────────────────────────────

class _ShimmerCard extends StatelessWidget {
  const _ShimmerCard();

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _box(double.infinity, 110),
          const SizedBox(height: 8),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _box(double.infinity, 13),
                const SizedBox(height: 5),
                _box(double.infinity, 13),
                const SizedBox(height: 5),
                _box(80, 11),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

Widget _box(double w, double h, {double radius = 4}) => Container(
  width:  w == double.infinity ? null : w,
  height: h,
  decoration: BoxDecoration(
    color:        AppColors.shimmerBase,
    borderRadius: BorderRadius.circular(radius),
  ),
);
