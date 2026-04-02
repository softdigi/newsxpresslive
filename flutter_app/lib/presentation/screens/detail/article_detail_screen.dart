import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:provider/provider.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import 'article_detail_controller.dart';
import 'widgets/article_actions_widget.dart';
import 'widgets/article_content_widget.dart';
import 'widgets/comment_section_widget.dart';
import 'widgets/comment_input_widget.dart';

/// Thin coordinator screen. All state lives in [ArticleDetailController].
class ArticleDetailScreen extends StatelessWidget {
  const ArticleDetailScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider<ArticleDetailController>(
      create: (_) => ArticleDetailController(slug),
      child: const _ArticleDetailView(),
    );
  }
}

class _ArticleDetailView extends StatelessWidget {
  const _ArticleDetailView();

  @override
  Widget build(BuildContext context) {
    final ctrl = context.watch<ArticleDetailController>();

    if (ctrl.loading) {
      return const Scaffold(
        body: Center(
            child: CircularProgressIndicator(color: AppColors.primary)),
      );
    }

    if (ctrl.error != null || ctrl.article == null) {
      return Scaffold(
        appBar: AppBar(),
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(ctrl.error ?? 'Article not found'),
              const SizedBox(height: 12),
              ElevatedButton(
                onPressed: ctrl.loadArticle,
                child: const Text(AppStrings.retry),
              ),
            ],
          ),
        ),
      );
    }

    final art = ctrl.article!;

    return Scaffold(
      body: CustomScrollView(
        slivers: [
          // ── App Bar with hero image ──────────────────────────────────
          SliverAppBar(
            expandedHeight: art.featuredImage != null ? 220 : 56,
            pinned:         true,
            flexibleSpace:  art.featuredImage != null
                ? FlexibleSpaceBar(
                    background: CachedNetworkImage(
                      imageUrl: art.featuredImage!,
                      fit:      BoxFit.cover,
                    ),
                  )
                : null,
            actions: [
              ArticleActionsWidget(
                article: art,
                onShare: ctrl.share,
              ),
            ],
          ),

          // ── Article body + comments ──────────────────────────────────
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  ArticleContentWidget(article: art),

                  const Divider(height: 32),

                  CommentSectionWidget(
                    comments:    ctrl.comments,
                    commLoading: ctrl.commLoading,
                    typers:      ctrl.typers,
                  ),

                  const SizedBox(height: 24),

                  CommentInputWidget(
                    nameCtrl:    ctrl.nameCtrl,
                    emailCtrl:   ctrl.emailCtrl,
                    contentCtrl: ctrl.contentCtrl,
                    onSubmit:    ctrl.submitComment,
                    submitting:  ctrl.submitting,
                    commMsg:     ctrl.commMsg,
                  ),

                  const SizedBox(height: 40),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
