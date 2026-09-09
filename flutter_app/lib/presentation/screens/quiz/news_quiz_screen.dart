import 'dart:async';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import 'package:share_plus/share_plus.dart';
import 'package:provider/provider.dart';
import '../../../core/constants/app_colors.dart';
import '../../../providers/auth_provider.dart';

// ── Models ────────────────────────────────────────────────────────────────────

class NewsQuizQuestion {
  final int id;
  final String question;
  final Map<String, String> options;
  final String difficulty;
  final String? thumbnailUrl;
  String? selectedOption;

  NewsQuizQuestion({
    required this.id,
    required this.question,
    required this.options,
    required this.difficulty,
    this.thumbnailUrl,
  });

  factory NewsQuizQuestion.fromJson(Map<String, dynamic> j) =>
      NewsQuizQuestion(
        id: j['id'] as int,
        question: j['question'] ?? '',
        options: Map<String, String>.from(j['options'] ?? {}),
        difficulty: j['difficulty'] ?? 'easy',
        thumbnailUrl: j['thumbnail_url'] as String?,
      );
}

// ── Quiz Screen ───────────────────────────────────────────────────────────────

class NewsQuizScreen extends StatefulWidget {
  const NewsQuizScreen({super.key});

  @override
  State<NewsQuizScreen> createState() => _NewsQuizScreenState();
}

class _NewsQuizScreenState extends State<NewsQuizScreen>
    with SingleTickerProviderStateMixin {
  static const _baseUrl = 'https://newsxpresslive.com/web/api/quiz/daily_news.php';

  List<NewsQuizQuestion> _questions = [];
  int _currentIndex = 0;
  bool _loading = true;
  bool _submitting = false;
  bool _alreadyDone = false;
  String? _error;

  // Timer per question (60s)
  Timer? _questionTimer;
  int _secondsLeft = 60;

  // Result state
  bool _showResult = false;
  int _score = 0;
  int _correct = 0;
  int _rank = 0;
  int _coins = 0;
  Map<String, dynamic> _serverAnswers = {};

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadQuiz());
  }

  @override
  void dispose() {
    _questionTimer?.cancel();
    super.dispose();
  }

  String get _uid =>
      context.read<AuthProvider?>()?.user?.uid ?? '';

  Future<void> _loadQuiz() async {
    setState(() { _loading = true; _error = null; });
    try {
      final res = await http
          .get(Uri.parse(_baseUrl), headers: {'X-User-Uid': _uid})
          .timeout(const Duration(seconds: 15));
      final json = jsonDecode(res.body) as Map<String, dynamic>;
      if (json['success'] == true) {
        final qs = (json['questions'] as List? ?? [])
            .map((q) => NewsQuizQuestion.fromJson(q as Map<String, dynamic>))
            .toList();
        setState(() {
          _questions = qs;
          _alreadyDone = json['completed'] == true;
        });
        if (!_alreadyDone && qs.isNotEmpty) _startTimer();
      } else {
        setState(() => _error = json['error'] ?? 'Quiz unavailable');
      }
    } catch (e) {
      setState(() => _error = 'Network error. Please try again.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _startTimer() {
    _questionTimer?.cancel();
    setState(() => _secondsLeft = 60);
    _questionTimer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_secondsLeft <= 0) {
        t.cancel();
        _moveNext(autoAdvance: true);
      } else {
        setState(() => _secondsLeft--);
      }
    });
  }

  void _selectOption(String option) {
    if (_questions[_currentIndex].selectedOption != null) return;
    setState(() => _questions[_currentIndex].selectedOption = option);
  }

  void _moveNext({bool autoAdvance = false}) {
    _questionTimer?.cancel();
    if (_currentIndex < _questions.length - 1) {
      setState(() => _currentIndex++);
      _startTimer();
    } else {
      _submitQuiz();
    }
  }

  Future<void> _submitQuiz() async {
    setState(() { _submitting = true; });
    _questionTimer?.cancel();

    final answers = <String, String>{};
    for (final q in _questions) {
      if (q.selectedOption != null) {
        answers[q.id.toString()] = q.selectedOption!;
      }
    }

    try {
      final res = await http
          .post(
            Uri.parse(_baseUrl),
            headers: {
              'Content-Type': 'application/json',
              'X-User-Uid': _uid,
            },
            body: jsonEncode({'answers': answers}),
          )
          .timeout(const Duration(seconds: 20));
      final json = jsonDecode(res.body) as Map<String, dynamic>;
      if (json['success'] == true) {
        setState(() {
          _score            = json['score'] ?? 0;
          _correct          = json['correct_answers'] ?? 0;
          _rank             = json['rank'] ?? 0;
          _coins            = json['coins_earned'] ?? 0;
          _serverAnswers    = Map<String, dynamic>.from(json['answers'] ?? {});
          _showResult       = true;
        });
      }
    } catch (_) {
      // Show result locally even if submission fails
      int c = _questions
          .where((q) => q.selectedOption != null)
          .length;
      setState(() { _correct = c; _score = c * 10; _showResult = true; });
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  void _shareScore() {
    Share.share(
      '🎯 मैंने NewsXpressLive Daily Quiz में $_correct/${_questions.length} सही जवाब दिए!\n'
      'मेरा score: $_score | Rank: #$_rank\n\n'
      '📱 NewsXpressLive app download karo aur quiz try karo!',
      subject: 'My Quiz Score',
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (_error != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Daily Quiz')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.quiz_rounded, size: 48, color: Colors.grey),
                const SizedBox(height: 12),
                Text(_error!, textAlign: TextAlign.center),
                const SizedBox(height: 16),
                ElevatedButton(onPressed: _loadQuiz, child: const Text('Retry')),
              ],
            ),
          ),
        ),
      );
    }
    if (_alreadyDone) {
      return Scaffold(
        appBar: AppBar(title: const Text('Daily Quiz')),
        body: const Center(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('✅', style: TextStyle(fontSize: 48)),
                SizedBox(height: 12),
                Text('आपने आज का Quiz पहले ही complete कर लिया है!',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 16)),
                SizedBox(height: 8),
                Text('कल फिर आएं', style: TextStyle(color: Colors.grey)),
              ],
            ),
          ),
        ),
      );
    }
    if (_showResult) return _buildResult();
    if (_questions.isEmpty) {
      return Scaffold(
        appBar: AppBar(title: const Text('Daily Quiz')),
        body: const Center(child: Text('आज के quiz के लिए questions नहीं हैं।')),
      );
    }
    return _buildQuestion();
  }

  Widget _buildQuestion() {
    final q = _questions[_currentIndex];
    final total = _questions.length;

    return Scaffold(
      appBar: AppBar(
        title: Text('Question ${_currentIndex + 1}/$total'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(4),
          child: LinearProgressIndicator(
            value: (_currentIndex + 1) / total,
            backgroundColor: Colors.grey.shade300,
            valueColor: const AlwaysStoppedAnimation(AppColors.primary),
          ),
        ),
      ),
      body: Column(
        children: [
          // Timer
          Container(
            padding:
                const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            color: _secondsLeft <= 10
                ? AppColors.primary.withAlpha(20)
                : null,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                Icon(Icons.timer_outlined,
                    color:
                        _secondsLeft <= 10 ? AppColors.primary : Colors.grey,
                    size: 16),
                const SizedBox(width: 4),
                Text(
                  '$_secondsLeft s',
                  style: TextStyle(
                      color: _secondsLeft <= 10
                          ? AppColors.primary
                          : Colors.grey,
                      fontWeight: FontWeight.bold),
                ),
              ],
            ),
          ),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Thumbnail
                  if (q.thumbnailUrl != null)
                    ClipRRect(
                      borderRadius: BorderRadius.circular(10),
                      child: Image.network(q.thumbnailUrl!,
                          height: 140,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) =>
                              const SizedBox.shrink()),
                    ),
                  const SizedBox(height: 12),

                  // Difficulty chip
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Chip(
                      label: Text(q.difficulty.toUpperCase()),
                      backgroundColor: _diffColor(q.difficulty).withAlpha(30),
                      labelStyle: TextStyle(
                          color: _diffColor(q.difficulty),
                          fontSize: 10,
                          fontWeight: FontWeight.bold),
                    ),
                  ),
                  const SizedBox(height: 8),

                  // Question
                  Text(q.question,
                      style: const TextStyle(
                          fontSize: 17, fontWeight: FontWeight.w600,
                          height: 1.5)),
                  const SizedBox(height: 20),

                  // Options
                  ...q.options.entries.map(
                      (e) => _OptionCard(
                            key: ValueKey(e.key),
                            letter: e.key.toUpperCase(),
                            text: e.value,
                            selected: q.selectedOption == e.key,
                            onTap: q.selectedOption == null
                                ? () => _selectOption(e.key)
                                : null,
                          )),
                ],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14)),
                onPressed: q.selectedOption != null ? _moveNext : null,
                child: Text(_currentIndex < _questions.length - 1
                    ? 'Next →'
                    : 'Submit Quiz'),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildResult() {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Quiz Result'),
        automaticallyImplyLeading: false,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Score card
            Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                    colors: [AppColors.primary, Color(0xFFFF6B6B)]),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Column(
                children: [
                  const Text('🎯', style: TextStyle(fontSize: 48)),
                  const SizedBox(height: 8),
                  Text('$_correct/${_questions.length}',
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 40,
                          fontWeight: FontWeight.bold)),
                  Text('correct answers',
                      style: TextStyle(
                          color: Colors.white.withAlpha(200),
                          fontSize: 16)),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                    children: [
                      _StatChip(label: 'Score', value: '$_score'),
                      _StatChip(label: 'Rank', value: '#$_rank'),
                      _StatChip(
                          label: 'Coins',
                          value: '+$_coins 🪙'),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // Answer review
            const Text('Answer Review',
                style:
                    TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            ..._questions.map((q) {
              final correct = _serverAnswers[q.id.toString()]?['correct'];
              final explanation =
                  _serverAnswers[q.id.toString()]?['explanation'];
              final isRight = q.selectedOption == correct;
              return Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(isRight ? '✅' : '❌',
                              style: const TextStyle(fontSize: 18)),
                          const SizedBox(width: 8),
                          Expanded(
                              child: Text(q.question,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w500))),
                        ],
                      ),
                      if (explanation != null) ...[
                        const SizedBox(height: 6),
                        Text(explanation,
                            style: const TextStyle(
                                fontSize: 12, color: Colors.grey)),
                      ],
                    ],
                  ),
                ),
              );
            }),

            const SizedBox(height: 16),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF25D366),
                  foregroundColor: Colors.white),
              onPressed: _shareScore,
              icon: const Icon(Icons.share),
              label: const Text('Share Score'),
            ),
          ],
        ),
      ),
    );
  }

  Color _diffColor(String diff) {
    switch (diff) {
      case 'hard':   return AppColors.primary;
      case 'medium': return AppColors.accent;
      default:       return const Color(0xFF1D9E75);
    }
  }
}

class _OptionCard extends StatelessWidget {
  final String letter;
  final String text;
  final bool selected;
  final VoidCallback? onTap;

  const _OptionCard({
    super.key,
    required this.letter,
    required this.text,
    required this.selected,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: selected
              ? AppColors.primary.withAlpha(20)
              : (isDark ? AppColors.cardDark : Colors.grey.shade50),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
            color: selected ? AppColors.primary : Colors.grey.shade300,
            width: selected ? 2 : 1,
          ),
        ),
        child: Row(
          children: [
            Container(
              width: 28,
              height: 28,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: selected ? AppColors.primary : Colors.grey.shade200,
              ),
              child: Center(
                child: Text(letter,
                    style: TextStyle(
                        color: selected ? Colors.white : Colors.black87,
                        fontWeight: FontWeight.bold,
                        fontSize: 13)),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(child: Text(text, style: const TextStyle(fontSize: 14))),
          ],
        ),
      ),
    );
  }
}

class _StatChip extends StatelessWidget {
  final String label;
  final String value;
  const _StatChip({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(value,
            style: const TextStyle(
                color: Colors.white,
                fontSize: 18,
                fontWeight: FontWeight.bold)),
        Text(label,
            style: TextStyle(
                color: Colors.white.withAlpha(180), fontSize: 12)),
      ],
    );
  }
}

// ── Streak indicator widget for HomeScreen ────────────────────────────────────

class QuizStreakBadge extends StatelessWidget {
  final int streakDays;
  const QuizStreakBadge({super.key, required this.streakDays});

  @override
  Widget build(BuildContext context) {
    if (streakDays < 2) return const SizedBox.shrink();
    return GestureDetector(
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => const NewsQuizScreen()),
      ),
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: const Color(0xFFFFF3E0),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: AppColors.accent),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('🔥', style: TextStyle(fontSize: 18)),
            const SizedBox(width: 6),
            Text('$streakDays-day quiz streak!',
                style: const TextStyle(
                    color: Color(0xFFE65100),
                    fontWeight: FontWeight.bold)),
          ],
        ),
      ),
    );
  }
}
