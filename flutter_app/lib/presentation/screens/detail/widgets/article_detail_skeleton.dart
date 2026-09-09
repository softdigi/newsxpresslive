import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';
import '../../../../core/constants/app_colors.dart';

/// Shimmer skeleton shown while [ArticleDetailController] loads the article.
/// Mirrors the visual structure of the real article detail layout so there
/// is no jarring layout shift when the content arrives.
class ArticleDetailSkeleton extends StatelessWidget {
  const ArticleDetailSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Shimmer.fromColors(
        baseColor:      AppColors.shimmerBase,
        highlightColor: AppColors.shimmerHighlight,
        child: CustomScrollView(
          slivers: [
            // Hero image placeholder
            SliverAppBar(
              expandedHeight: 220,
              pinned: true,
              flexibleSpace: FlexibleSpaceBar(
                background: _box(double.infinity, 220, radius: 0),
              ),
            ),
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 20, 16, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Category badge
                    _box(80, 20, radius: 4),
                    const SizedBox(height: 14),

                    // Title lines
                    _box(double.infinity, 22),
                    const SizedBox(height: 8),
                    _box(double.infinity, 22),
                    const SizedBox(height: 8),
                    _box(200, 22),
                    const SizedBox(height: 16),

                    // Meta row (author · time · read-time)
                    Row(children: [
                      _box(100, 14),
                      const SizedBox(width: 16),
                      _box(70, 14),
                      const SizedBox(width: 16),
                      _box(80, 14),
                    ]),
                    const SizedBox(height: 20),
                    const Divider(),
                    const SizedBox(height: 16),

                    // Body text lines
                    ..._textBlock(9),
                    const SizedBox(height: 12),
                    ..._textBlock(6),
                    const SizedBox(height: 12),
                    ..._textBlock(5),
                    const SizedBox(height: 40),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Generates alternating full-width and partial-width text line placeholders.
  List<Widget> _textBlock(int lines) {
    final widgets = <Widget>[];
    for (int i = 0; i < lines; i++) {
      widgets.add(_box(i == lines - 1 ? 180 : double.infinity, 14));
      if (i < lines - 1) widgets.add(const SizedBox(height: 8));
    }
    return widgets;
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
