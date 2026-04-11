import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../providers/poll_provider.dart';
import '../../../providers/auth_provider.dart';
import '../../../data/models/quiz_model.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';

/// Daily Quiz screen — one question per day.
///
/// Shows today's question with four options.  After the user selects an
/// answer and taps Submit, the correct answer and an explanation are
/// revealed.  A history tab shows past results.
class QuizScreen extends StatefulWidget {
  const QuizScreen({super.key});

  @override
  State<QuizScreen> createState() => _QuizScreenState();
}

class _QuizScreenState extends State<QuizScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabCtrl;

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: 2, vsync: this)
      ..addListener(() {
        if (!_tabCtrl.indexIsChanging && _tabCtrl.index == 1) {
          _loadHistory();
        }
      });
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadQuiz());
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  void _loadQuiz() {
    final uid = context.read<AuthProvider?>()?.user?.uid;
    context.read<PollProvider>().loadQuiz(firebaseUid: uid);
  }

  void _loadHistory() {
    final uid = context.read<AuthProvider?>()?.user?.uid;
    if (uid == null) return;
    context.read<PollProvider>().loadHistory(uid);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(AppStrings.quizTitle),
        bottom: TabBar(
          controller: _tabCtrl,
          tabs: const [
            Tab(text: AppStrings.quizTitle),
            Tab(text: AppStrings.quizHistoryTitle),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabCtrl,
        children: const [
          _TodayQuizTab(),
          _QuizHistoryTab(),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Today's Quiz
// ─────────────────────────────────────────────────────────────────────────────

class _TodayQuizTab extends StatefulWidget {
  const _TodayQuizTab();

  @override
  State<_TodayQuizTab> createState() => _TodayQuizTabState();
}

class _TodayQuizTabState extends State<_TodayQuizTab> {
  int? _selected;

  @override
  Widget build(BuildContext context) {
    return Consumer<PollProvider>(
      builder: (context, prov, _) {
        if (prov.quizLoading) {
          return const Center(child: CircularProgressIndicator());
        }
        if (prov.quizError != null) {
          return _ErrorView(
            message: AppStrings.quizLoadError,
            onRetry: () => prov.loadQuiz(
              firebaseUid: context.read<AuthProvider?>()?.user?.uid,
            ),
          );
        }
        final quiz = prov.quiz;
        if (quiz == null) {
          return const _EmptyView(message: AppStrings.quizNoQuiz);
        }
        return RefreshIndicator(
          onRefresh: () async => prov.loadQuiz(
            firebaseUid: context.read<AuthProvider?>()?.user?.uid,
          ),
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _QuizCard(
                quiz:         quiz,
                selected:     _selected,
                onSelect:     (i) => setState(() => _selected = i),
                onSubmit:     quiz.hasAnswered ? null : () => _submit(context, quiz, prov),
                submitting:   prov.quizSubmitting,
              ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _submit(
    BuildContext ctx,
    QuizModel quiz,
    PollProvider prov,
  ) async {
    if (_selected == null) return;
    final auth = ctx.read<AuthProvider?>();
    if (auth?.user == null) {
      ScaffoldMessenger.of(ctx).showSnackBar(
        const SnackBar(content: Text(AppStrings.pollAuthRequired)),
      );
      return;
    }
    await prov.submitAnswer(
      selectedIndex: _selected!,
      firebaseUid:   auth!.user!.uid,
    );
    if (mounted) setState(() => _selected = null);
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Quiz Card
// ─────────────────────────────────────────────────────────────────────────────

class _QuizCard extends StatelessWidget {
  const _QuizCard({
    required this.quiz,
    required this.selected,
    required this.onSelect,
    required this.onSubmit,
    required this.submitting,
  });

  final QuizModel      quiz;
  final int?           selected;
  final ValueChanged<int> onSelect;
  final VoidCallback?  onSubmit;
  final bool           submitting;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final bg     = isDark ? AppColors.cardDark : AppColors.cardLight;
    final textPrimary =
        isDark ? AppColors.textPrimaryDark : AppColors.textPrimaryLight;
    final textSec =
        isDark ? AppColors.textSecondaryDark : AppColors.textSecondaryLight;

    final answered = quiz.hasAnswered;

    return Container(
      decoration: BoxDecoration(
        color:        bg,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color:      Colors.black.withOpacity(isDark ? 0.30 : 0.08),
            blurRadius: 10,
            offset:     const Offset(0, 3),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Header
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color:        AppColors.primary,
              borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
            ),
            child: Row(
              children: [
                const Icon(Icons.quiz_rounded, color: Colors.white, size: 20),
                const SizedBox(width: 8),
                Text(
                  '${AppStrings.quizTitle} — ${quiz.quizDate}',
                  style: const TextStyle(
                    color:      Colors.white,
                    fontSize:   14,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ],
            ),
          ),

          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // Question
                Text(
                  quiz.question,
                  style: TextStyle(
                    color:      textPrimary,
                    fontSize:   16,
                    fontWeight: FontWeight.w600,
                    height:     1.4,
                  ),
                ),
                const SizedBox(height: 20),

                // Options
                ...quiz.options.map((opt) => _QuizOption(
                  option:       opt,
                  isSelected:   selected == opt.index,
                  isCorrect:    answered && quiz.correctIndex == opt.index,
                  isWrong:      answered &&
                                quiz.userAttempt?.selectedIndex == opt.index &&
                                quiz.correctIndex != opt.index,
                  isDisabled:   answered,
                  onTap:        answered ? null : () => onSelect(opt.index),
                )),

                // Result banner
                if (answered)
                  _ResultBanner(quiz: quiz),

                // Explanation
                if (answered && quiz.explanation != null) ...[
                  const SizedBox(height: 16),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color:        AppColors.primary.withOpacity(0.08),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          AppStrings.quizExplanation,
                          style: TextStyle(
                            color:      AppColors.primary,
                            fontWeight: FontWeight.bold,
                            fontSize:   13,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          quiz.explanation!,
                          style: TextStyle(color: textPrimary, fontSize: 13.5),
                        ),
                      ],
                    ),
                  ),
                ],

                // Submit button
                if (!answered) ...[
                  const SizedBox(height: 20),
                  ElevatedButton(
                    onPressed: selected != null && !submitting ? onSubmit : null,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AppColors.primary,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    child: submitting
                        ? const SizedBox(
                            height: 20,
                            width:  20,
                            child:  CircularProgressIndicator(
                              color:       Colors.white,
                              strokeWidth: 2,
                            ),
                          )
                        : const Text(
                            AppStrings.quizSubmitButton,
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize:   15,
                            ),
                          ),
                  ),
                ],

                if (answered) ...[
                  const SizedBox(height: 4),
                  Text(
                    AppStrings.quizAlreadyAnswered,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color:    textSec,
                      fontSize: 11,
                    ),
                  ),
                ],
              ],
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────

class _QuizOption extends StatelessWidget {
  const _QuizOption({
    required this.option,
    required this.isSelected,
    required this.isCorrect,
    required this.isWrong,
    required this.isDisabled,
    this.onTap,
  });

  final QuizOption   option;
  final bool         isSelected;
  final bool         isCorrect;
  final bool         isWrong;
  final bool         isDisabled;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final textPrimary =
        isDark ? AppColors.textPrimaryDark : AppColors.textPrimaryLight;

    Color borderColor;
    Color? fillColor;
    Color  textColor = textPrimary;
    Widget? trailing;

    if (isCorrect) {
      borderColor = Colors.green;
      fillColor   = Colors.green.withOpacity(0.12);
      textColor   = Colors.green;
      trailing    = const Icon(Icons.check_circle, color: Colors.green, size: 20);
    } else if (isWrong) {
      borderColor = Colors.red;
      fillColor   = Colors.red.withOpacity(0.10);
      textColor   = Colors.red;
      trailing    = const Icon(Icons.cancel, color: Colors.red, size: 20);
    } else if (isSelected) {
      borderColor = AppColors.accent;
      fillColor   = AppColors.accent.withOpacity(0.12);
    } else {
      borderColor = AppColors.divider;
      fillColor   = null;
    }

    return GestureDetector(
      onTap: isDisabled ? null : onTap,
      child: AnimatedContainer(
        duration:   const Duration(milliseconds: 250),
        margin:     const EdgeInsets.only(bottom: 10),
        padding:    const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
        decoration: BoxDecoration(
          color:        fillColor,
          borderRadius: BorderRadius.circular(12),
          border:       Border.all(color: borderColor, width: 1.5),
        ),
        child: Row(
          children: [
            Expanded(
              child: Text(
                '${String.fromCharCode(65 + option.index)}.  ${option.text}',
                style: TextStyle(
                  color:      textColor,
                  fontSize:   14.5,
                  fontWeight: isSelected || isCorrect || isWrong
                      ? FontWeight.w600
                      : FontWeight.normal,
                ),
              ),
            ),
            if (trailing != null) trailing,
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────

class _ResultBanner extends StatelessWidget {
  const _ResultBanner({required this.quiz});

  final QuizModel quiz;

  @override
  Widget build(BuildContext context) {
    final attempt = quiz.userAttempt!;
    final correct = attempt.isCorrect;
    return AnimatedContainer(
      duration:   const Duration(milliseconds: 400),
      margin:     const EdgeInsets.symmetric(vertical: 12),
      padding:    const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color:        correct
            ? Colors.green.withOpacity(0.12)
            : Colors.red.withOpacity(0.10),
        borderRadius: BorderRadius.circular(12),
        border:       Border.all(
          color: correct ? Colors.green : Colors.red,
          width: 1.5,
        ),
      ),
      child: Row(
        children: [
          Icon(
            correct ? Icons.emoji_events_rounded : Icons.sentiment_dissatisfied,
            color: correct ? Colors.amber : Colors.red,
            size:  28,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              correct ? AppStrings.quizCorrect : AppStrings.quizWrong,
              style: TextStyle(
                color:      correct ? Colors.green : Colors.red,
                fontWeight: FontWeight.bold,
                fontSize:   15,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// History Tab
// ─────────────────────────────────────────────────────────────────────────────

class _QuizHistoryTab extends StatelessWidget {
  const _QuizHistoryTab();

  @override
  Widget build(BuildContext context) {
    return Consumer<PollProvider>(
      builder: (context, prov, _) {
        if (prov.historyLoading) {
          return const Center(child: CircularProgressIndicator());
        }
        if (prov.history.isEmpty) {
          return const _EmptyView(message: AppStrings.quizHistoryEmpty);
        }
        return ListView.builder(
          padding:     const EdgeInsets.all(16),
          itemCount:   prov.history.length,
          itemBuilder: (ctx, i) => _HistoryTile(entry: prov.history[i]),
        );
      },
    );
  }
}

class _HistoryTile extends StatelessWidget {
  const _HistoryTile({required this.entry});

  final QuizHistoryEntry entry;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final bg     = isDark ? AppColors.cardDark : AppColors.cardLight;

    return Container(
      margin:  const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color:        bg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: entry.isCorrect
              ? Colors.green.withOpacity(0.40)
              : Colors.red.withOpacity(0.30),
          width: 1,
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            entry.isCorrect
                ? Icons.check_circle_rounded
                : Icons.cancel_rounded,
            color: entry.isCorrect ? Colors.green : Colors.red,
            size:  22,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  entry.quizDate,
                  style: TextStyle(
                    color:    isDark
                        ? AppColors.textSecondaryDark
                        : AppColors.textSecondaryLight,
                    fontSize: 11,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  entry.question,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color:      isDark
                        ? AppColors.textPrimaryDark
                        : AppColors.textPrimaryLight,
                    fontSize:   13.5,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text(
            '+${entry.score}',
            style: TextStyle(
              color:      entry.isCorrect ? Colors.green : Colors.red,
              fontWeight: FontWeight.bold,
              fontSize:   14,
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

class _EmptyView extends StatelessWidget {
  const _EmptyView({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Text(
          message,
          textAlign: TextAlign.center,
          style: TextStyle(
            color:    isDark
                ? AppColors.textSecondaryDark
                : AppColors.textSecondaryLight,
            fontSize: 15,
          ),
        ),
      ),
    );
  }
}

class _ErrorView extends StatelessWidget {
  const _ErrorView({required this.message, required this.onRetry});

  final String       message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.error_outline, color: AppColors.primary, size: 48),
          const SizedBox(height: 12),
          Text(message,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 14)),
          const SizedBox(height: 16),
          ElevatedButton(
            onPressed: onRetry,
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.primary,
              foregroundColor: Colors.white,
            ),
            child: const Text(AppStrings.retry),
          ),
        ],
      ),
    );
  }
}
