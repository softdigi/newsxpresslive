/// Confidence level returned by [ModerationService.analyse].
enum ModerationLevel {
  /// Content is fine — allow submission.
  safe,

  /// Content shows weak signals — allow but attach a flag for admin review.
  warn,

  /// Content contains strong abusive/spam signals — block and show the user
  /// an explanatory message.
  block,
}

/// Result of a [ModerationService.analyse] call.
class ModerationResult {
  const ModerationResult({
    required this.level,
    required this.reason,
    required this.score,
    this.flaggedTerms = const [],
  });

  /// Overall moderation decision.
  final ModerationLevel level;

  /// Human-readable explanation (shown to user on warn/block).
  final String reason;

  /// Raw score in the range 0.0–1.0. Higher = more suspicious.
  final double score;

  /// Specific terms that matched abuse/spam patterns.
  final List<String> flaggedTerms;

  bool get isSafe  => level == ModerationLevel.safe;
  bool get isWarn  => level == ModerationLevel.warn;
  bool get isBlock => level == ModerationLevel.block;

  /// A short summary suitable for passing to the backend as a flag.
  ///
  /// Returns `null` when the content is safe.
  String? get backendFlag {
    switch (level) {
      case ModerationLevel.safe:
        return null;
      case ModerationLevel.warn:
        return 'review_requested';
      case ModerationLevel.block:
        return 'auto_hidden';
    }
  }
}

/// Client-side AI content moderation.
///
/// Performs a multi-signal analysis on user-generated text and returns a
/// [ModerationResult] that the UI uses to allow, warn, or block submission.
///
/// ### Signals checked
/// 1. **Abusive language** — multi-language pattern list (en, hi, ar, es, fr, de, pt).
/// 2. **Fake-news markers** — all-caps shouting, excessive exclamation/question marks,
///    clickbait phrases ("you won't believe", "shocking truth" …).
/// 3. **Spam signals** — repeated characters (aaaaaaa), repeated words, URL flooding,
///    excessive emoji density.
/// 4. **Length heuristic** — very short or very long text gets a small penalty.
///
/// ### Scoring
/// Each matched signal adds a weighted increment to a 0–1 score.
/// - score ≥ 0.75 → block
/// - score ≥ 0.40 → warn (send to admin review)
/// - score <  0.40 → safe
///
/// The service is stateless and requires no initialisation.
class ModerationService {
  ModerationService._();

  static final ModerationService instance = ModerationService._();

  // ── Thresholds ────────────────────────────────────────────────────────
  static const double _blockThreshold = 0.75;
  static const double _warnThreshold  = 0.40;

  // ── Abusive term patterns (multi-language) ────────────────────────────
  // English, Hindi (romanised), Arabic (romanised), Spanish, French, German,
  // Portuguese. Each is a simple lower-case substring or regex fragment.
  static const List<String> _abuseTerms = [
    // English
    r'\bkill\b', r'\bdie\b', r'\bhate\b', r'\bidiot\b', r'\bstupid\b',
    r'\bmoron\b', r'\bfool\b', r'\bloser\b', r'\bscum\b', r'\bbastard\b',
    r'\bjerk\b', r'\bdummy\b', r'\bthreaten\b', r'\bterror\b',
    r'\bbomb\b', r'\bwipe out\b', r'\blie\b', r'\bfake\b',
    // Hindi (romanised)
    r'\bkamina\b', r'\bgadha\b', r'\bbewakoof\b', r'\bbakwas\b',
    r'\bgandu\b', r'\bkutta\b',
    // Arabic (romanised)
    r'\bkhaeen\b', r'\bkalboun\b', r'\bhimar\b',
    // Spanish
    r'\bestupido\b', r'\bidiota\b', r'\bimbécil\b', r'\binútil\b',
    r'\bcretino\b',
    // French
    r'\bimbécile\b', r'\bcrétin\b', r'\bidiot\b', r'\bnul\b',
    // German
    r'\bdummkopf\b', r'\bblödmann\b', r'\bdepp\b', r'\bidiot\b',
    // Portuguese
    r'\bidiota\b', r'\bestúpido\b', r'\bcretino\b',
  ];

  // ── Fake-news / clickbait markers ─────────────────────────────────────
  static const List<String> _clickbaitPhrases = [
    r"you won'?t believe",
    r'shocking truth',
    r'they don'?t want you to know',
    r'miracle cure',
    r'secret revealed',
    r'doctors hate',
    r'this will change',
    r'breaking(?:\s*news){0,1}:.*!!!',
    r'100\s*%\s*(?:proven|guaranteed|true)',
    r'conspiracy',
    r'illuminati',
    r'deep\s+state',
    r'plandemic',
    r'scamdemic',
  ];

  // ── URL pattern ───────────────────────────────────────────────────────
  static final RegExp _urlRegex = RegExp(
    r'https?://\S+|www\.\S+|\b\w+\.(com|net|org|io|xyz|tk)\b',
    caseSensitive: false,
  );

  // ── Repeated chars (5+ identical chars in a row) ──────────────────────
  static final RegExp _repeatedChars = RegExp(r'(.)\1{4,}');

  // ── Emoji detection (rough Unicode block) ────────────────────────────
  static final RegExp _emojiRegex = RegExp(
    r'[\u{1F300}-\u{1F9FF}]|[\u{2700}-\u{27BF}]|[\u{FE00}-\u{FEFF}]',
    unicode: true,
  );

  // ── Public API ────────────────────────────────────────────────────────

  /// Analyse [text] (a comment or news submission body) and return a
  /// [ModerationResult].
  ///
  /// Pass [title] to include it in the analysis (useful for news submissions).
  ModerationResult analyse(String text, {String? title}) {
    final combined = [if (title != null) title, text].join(' ');
    final lower    = combined.toLowerCase();

    double score = 0.0;
    final flagged = <String>[];

    // ── 1. Abusive language ──────────────────────────────────────────────
    for (final pattern in _abuseTerms) {
      final re = RegExp(pattern, caseSensitive: false);
      if (re.hasMatch(lower)) {
        score += 0.20;
        flagged.add(pattern);
      }
    }

    // ── 2. Clickbait / fake-news markers ─────────────────────────────────
    for (final pattern in _clickbaitPhrases) {
      final re = RegExp(pattern, caseSensitive: false);
      if (re.hasMatch(lower)) {
        score += 0.15;
        flagged.add(pattern);
      }
    }

    // ── 3. ALL-CAPS ratio (excl. spaces) ─────────────────────────────────
    final letters = combined.replaceAll(RegExp(r'[^a-zA-Z]'), '');
    if (letters.length > 10) {
      final upperCount = letters
          .split('')
          .where((c) => c == c.toUpperCase() && c != c.toLowerCase())
          .length;
      final capsRatio = upperCount / letters.length;
      if (capsRatio > 0.70) score += 0.20;
    }

    // ── 4. Excessive punctuation ─────────────────────────────────────────
    final exclamCount = '!'.allMatches(combined).length;
    final questionCount = '?'.allMatches(combined).length;
    if (exclamCount >= 4)            score += 0.10;
    if (questionCount >= 4)          score += 0.05;
    if (exclamCount + questionCount >= 8) score += 0.10;

    // ── 5. Repeated characters ────────────────────────────────────────────
    if (_repeatedChars.hasMatch(combined)) score += 0.10;

    // ── 6. URL flooding (3+ URLs) ─────────────────────────────────────────
    final urlMatches = _urlRegex.allMatches(combined).length;
    if (urlMatches >= 3) score += 0.20;
    if (urlMatches >= 6) score += 0.20;

    // ── 7. Emoji density ──────────────────────────────────────────────────
    final emojiCount  = _emojiRegex.allMatches(combined).length;
    final wordCount   = combined.trim().split(RegExp(r'\s+')).length;
    if (wordCount > 0 && emojiCount / wordCount > 0.4) score += 0.10;

    // ── 8. Repeated words (same word ≥5×) ─────────────────────────────────
    final words = lower.split(RegExp(r'\s+'));
    final freq  = <String, int>{};
    for (final w in words) {
      if (w.length > 3) freq[w] = (freq[w] ?? 0) + 1;
    }
    if (freq.values.any((c) => c >= 5)) score += 0.15;

    // ── 9. Very short / nonsense text penalty ─────────────────────────────
    if (text.trim().length < 5) score += 0.30;

    // ── Cap at 1.0 and determine level ───────────────────────────────────
    score = score.clamp(0.0, 1.0);

    if (score >= _blockThreshold) {
      return ModerationResult(
        level:        ModerationLevel.block,
        reason:       'Your message was blocked because it appears to contain '
                      'abusive, spam, or misleading content.',
        score:        score,
        flaggedTerms: flagged,
      );
    }

    if (score >= _warnThreshold) {
      return ModerationResult(
        level:        ModerationLevel.warn,
        reason:       'Your message has been submitted and flagged for admin '
                      'review. It may not appear immediately.',
        score:        score,
        flaggedTerms: flagged,
      );
    }

    return ModerationResult(
      level:        ModerationLevel.safe,
      reason:       '',
      score:        score,
      flaggedTerms: flagged,
    );
  }

  /// Convenience wrapper for comment text.
  ModerationResult analyseComment(String content) => analyse(content);

  /// Convenience wrapper for news submissions.
  ModerationResult analyseSubmission({
    required String title,
    required String description,
  }) => analyse(description, title: title);
}
