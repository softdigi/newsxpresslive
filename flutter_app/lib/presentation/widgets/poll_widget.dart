import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/poll_provider.dart';
import '../../providers/auth_provider.dart';
import '../../data/models/poll_model.dart';
import '../../core/constants/app_colors.dart';
import '../../core/constants/app_strings.dart';

/// Inline poll card displayed inside an article detail screen.
///
/// Usage:
/// ```dart
/// PollWidget(newsId: article.id)
/// ```
///
/// Loads polls automatically via [PollProvider].  Each poll is rendered as
/// a question with selectable options.  After voting, per-option percentages
/// are animated in.
class PollWidget extends StatelessWidget {
  const PollWidget({super.key, required this.newsId});

  final int newsId;

  @override
  Widget build(BuildContext context) {
    return Consumer<PollProvider>(
      builder: (context, prov, _) {
        // Trigger load on first build.
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (prov.pollsFor(newsId).isEmpty && !prov.isLoadingPolls(newsId)) {
            final uid =
                context.read<AuthProvider?>()?.user?.uid;
            prov.loadPollsForArticle(newsId, firebaseUid: uid);
          }
        });

        if (prov.isLoadingPolls(newsId)) {
          return const Padding(
            padding: EdgeInsets.symmetric(vertical: 16),
            child: Center(child: CircularProgressIndicator()),
          );
        }

        final polls = prov.pollsFor(newsId);
        if (polls.isEmpty) return const SizedBox.shrink();

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: polls
              .map((poll) => _PollCard(poll: poll, newsId: newsId))
              .toList(),
        );
      },
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────

class _PollCard extends StatefulWidget {
  const _PollCard({required this.poll, required this.newsId});

  final PollModel poll;
  final int       newsId;

  @override
  State<_PollCard> createState() => _PollCardState();
}

class _PollCardState extends State<_PollCard> {
  int? _pending; // option the user tapped before confirmation

  void _onOptionTap(BuildContext ctx, int optionIndex) {
    final auth = ctx.read<AuthProvider?>();
    if (auth == null || auth.user == null) {
      ScaffoldMessenger.of(ctx).showSnackBar(
        const SnackBar(content: Text(AppStrings.pollAuthRequired)),
      );
      return;
    }
    if (widget.poll.hasVoted) return; // already voted
    setState(() => _pending = optionIndex);
  }

  Future<void> _submitVote(BuildContext ctx) async {
    if (_pending == null) return;
    final auth = ctx.read<AuthProvider?>();
    if (auth?.user == null) return;

    final prov = ctx.read<PollProvider>();
    final ok   = await prov.vote(
      pollId:      widget.poll.id,
      optionIndex: _pending!,
      newsId:      widget.newsId,
      firebaseUid: auth!.user!.uid,
    );
    if (!ok && mounted) {
      ScaffoldMessenger.of(ctx).showSnackBar(
        const SnackBar(content: Text(AppStrings.pollVoteError)),
      );
    }
    if (mounted) setState(() => _pending = null);
  }

  @override
  Widget build(BuildContext context) {
    final poll   = widget.poll;
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final bg     = isDark ? AppColors.cardDark : AppColors.cardLight;
    final textPrimary =
        isDark ? AppColors.textPrimaryDark : AppColors.textPrimaryLight;
    final textSecondary =
        isDark ? AppColors.textSecondaryDark : AppColors.textSecondaryLight;

    final showResults = poll.hasVoted;

    return Container(
      margin:  const EdgeInsets.symmetric(vertical: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color:        bg,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: AppColors.primary.withOpacity(0.25),
          width: 1,
        ),
        boxShadow: [
          BoxShadow(
            color:      Colors.black.withOpacity(isDark ? 0.30 : 0.08),
            blurRadius: 8,
            offset:     const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Header
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color:        AppColors.primary,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  AppStrings.pollTitle,
                  style: const TextStyle(
                    color:      Colors.white,
                    fontSize:   11,
                    fontWeight: FontWeight.bold,
                    letterSpacing: 0.5,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  '${poll.totalVotes} ${AppStrings.pollTotalVotes}',
                  style: TextStyle(color: textSecondary, fontSize: 12),
                ),
              ),
              if (!poll.isActive)
                Text(
                  AppStrings.pollEnded,
                  style: TextStyle(color: textSecondary, fontSize: 12),
                ),
            ],
          ),
          const SizedBox(height: 12),

          // Question
          Text(
            poll.question,
            style: TextStyle(
              color:      textPrimary,
              fontSize:   15,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 14),

          // Options
          ...poll.options.map((opt) => _OptionTile(
            option:      opt,
            showResults: showResults,
            isVoted:     poll.userVote == opt.index,
            isPending:   _pending == opt.index,
            isDisabled:  poll.hasVoted || !poll.isActive,
            onTap:       () => _onOptionTap(context, opt.index),
          )),

          // Vote button (shown when user has selected but not yet submitted)
          if (_pending != null && !poll.hasVoted) ...[
            const SizedBox(height: 12),
            ElevatedButton(
              onPressed: () => _submitVote(context),
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10),
                ),
              ),
              child: const Text(AppStrings.pollVoteButton),
            ),
          ],

          if (poll.hasVoted) ...[
            const SizedBox(height: 8),
            Text(
              AppStrings.pollVoted,
              textAlign: TextAlign.center,
              style: TextStyle(
                color:    AppColors.primary,
                fontSize: 12,
                fontStyle: FontStyle.italic,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────

class _OptionTile extends StatelessWidget {
  const _OptionTile({
    required this.option,
    required this.showResults,
    required this.isVoted,
    required this.isPending,
    required this.isDisabled,
    required this.onTap,
  });

  final PollOption option;
  final bool       showResults;
  final bool       isVoted;
  final bool       isPending;
  final bool       isDisabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isDark   = Theme.of(context).brightness == Brightness.dark;
    final textPrimary =
        isDark ? AppColors.textPrimaryDark : AppColors.textPrimaryLight;

    Color barColor;
    Color borderColor;
    if (isVoted) {
      barColor    = AppColors.primary;
      borderColor = AppColors.primary;
    } else if (isPending) {
      barColor    = AppColors.accent;
      borderColor = AppColors.accent;
    } else {
      barColor    = AppColors.primary.withOpacity(0.35);
      borderColor = AppColors.divider;
    }

    return GestureDetector(
      onTap: isDisabled ? null : onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        child: Stack(
          children: [
            // Background fill (results bar)
            if (showResults)
              AnimatedContainer(
                duration: const Duration(milliseconds: 600),
                curve:    Curves.easeOut,
                height:   44,
                width:    double.infinity,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(10),
                  color:        Colors.transparent,
                ),
                child: FractionallySizedBox(
                  widthFactor: option.pct / 100,
                  alignment:   Alignment.centerLeft,
                  child: Container(
                    decoration: BoxDecoration(
                      color:        barColor.withOpacity(0.18),
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
              ),

            // Foreground: border + label + percentage
            Container(
              height:  44,
              padding: const EdgeInsets.symmetric(horizontal: 14),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: borderColor, width: 1.5),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      option.text,
                      style: TextStyle(
                        color:      textPrimary,
                        fontSize:   14,
                        fontWeight: isVoted || isPending
                            ? FontWeight.w600
                            : FontWeight.normal,
                      ),
                    ),
                  ),
                  if (showResults)
                    Text(
                      '${option.pct.toStringAsFixed(0)}%',
                      style: TextStyle(
                        color:      isVoted ? AppColors.primary : textPrimary,
                        fontSize:   13,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  if (isPending && !showResults)
                    const Icon(Icons.check_circle,
                        color: AppColors.accent, size: 18),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
