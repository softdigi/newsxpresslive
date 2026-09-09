import 'package:flutter/foundation.dart';
import 'package:flutter_tts/flutter_tts.dart';

/// Playback states for the TTS engine.
enum TtsPlayState { idle, loading, playing, paused }

/// Predefined speed steps shown in the player UI.
/// Each entry maps a user-facing label to the flutter_tts speechRate value
/// (range 0.0–1.0; 0.5 is the normal/default rate).
class TtsSpeed {
  const TtsSpeed._(this.label, this.rate);

  final String label;
  final double rate;

  static const slow   = TtsSpeed._('0.5×', 0.3);
  static const normal = TtsSpeed._('1×',   0.5);
  static const fast   = TtsSpeed._('1.5×', 0.65);
  static const faster = TtsSpeed._('2×',   0.8);

  static const all = [slow, normal, fast, faster];
}

/// Global TTS provider.
///
/// Registered at the root [MultiProvider] so that playback continues when
/// the user navigates away from the article detail screen.
class TtsProvider extends ChangeNotifier {
  TtsProvider() {
    _init();
  }

  final FlutterTts _tts = FlutterTts();

  TtsPlayState _state         = TtsPlayState.idle;
  TtsSpeed     _speed         = TtsSpeed.normal;
  int?         _articleId;
  String       _articleTitle  = '';

  /// Words spoken so far (updated by the progress handler).
  int _wordsSpoken = 0;
  /// Total word count for the current article text.
  int _totalWords  = 0;

  TtsPlayState get state        => _state;
  TtsSpeed     get speed        => _speed;
  int?         get articleId    => _articleId;
  String       get articleTitle => _articleTitle;
  int          get wordsSpoken  => _wordsSpoken;
  int          get totalWords   => _totalWords;

  bool get isIdle    => _state == TtsPlayState.idle;
  bool get isLoading => _state == TtsPlayState.loading;
  bool get isPlaying => _state == TtsPlayState.playing;
  bool get isPaused  => _state == TtsPlayState.paused;
  bool get isActive  => _state != TtsPlayState.idle;

  /// Progress as a value between 0.0 and 1.0.
  double get progress =>
      (_totalWords > 0) ? (_wordsSpoken / _totalWords).clamp(0.0, 1.0) : 0.0;

  // ── Setup ──────────────────────────────────────────────────────────────

  Future<void> _init() async {
    await _tts.setVolume(1.0);
    await _tts.setPitch(1.0);
    await _tts.setSpeechRate(_speed.rate);

    // ── iOS: enable background audio playback ────────────────────────────
    await _tts.setSharedInstance(true);
    await _tts.setIosAudioCategory(
      IosTextToSpeechAudioCategory.playback,
      [
        IosTextToSpeechAudioCategoryOptions.allowBluetooth,
        IosTextToSpeechAudioCategoryOptions.allowBluetoothA2DP,
        IosTextToSpeechAudioCategoryOptions.mixWithOthers,
        IosTextToSpeechAudioCategoryOptions.defaultToSpeaker,
      ],
      IosTextToSpeechAudioMode.defaultMode,
    );

    // ── Completion / error callbacks ─────────────────────────────────────
    _tts.setCompletionHandler(() {
      _state       = TtsPlayState.idle;
      _wordsSpoken = _totalWords;
      notifyListeners();
    });

    _tts.setErrorHandler((message) {
      _state = TtsPlayState.idle;
      notifyListeners();
    });

    _tts.setCancelHandler(() {
      _state = TtsPlayState.idle;
      notifyListeners();
    });

    // Progress handler tracks word-level position (Android + iOS 16+).
    _tts.setProgressHandler((text, start, end, word) {
      _wordsSpoken++;
      notifyListeners();
    });
  }

  // ── Public API ─────────────────────────────────────────────────────────

  /// Start reading [plainText] aloud.
  ///
  /// [articleId] and [title] are used by the player widget to show context.
  /// Call [StringUtils.stripHtml] before passing the text.
  Future<void> speak({
    required int    articleId,
    required String title,
    required String plainText,
  }) async {
    if (plainText.trim().isEmpty) return;

    // Stop any current playback first.
    await _tts.stop();

    _articleId   = articleId;
    _articleTitle = title;
    _wordsSpoken = 0;
    _totalWords  = plainText.trim().split(RegExp(r'\s+')).length;
    _state       = TtsPlayState.loading;
    notifyListeners();

    await _tts.setSpeechRate(_speed.rate);
    final result = await _tts.speak(plainText);
    if (result == 1) {
      _state = TtsPlayState.playing;
    } else {
      _state = TtsPlayState.idle;
    }
    notifyListeners();
  }

  /// Pause (iOS/Android) or stop (web/other) the current utterance.
  Future<void> pause() async {
    final result = await _tts.pause();
    if (result == 1) {
      _state = TtsPlayState.paused;
      notifyListeners();
    }
  }

  /// Resume a paused utterance.
  Future<void> resume() async {
    // flutter_tts does not have a native resume; re-speak is not ideal for
    // long articles. We call speak() on the platform again – on Android this
    // picks up via the queue; on iOS the utterance is re-started.
    // A better experience is to track char offset and slice the remaining
    // text, but that is platform-specific. For this implementation we simply
    // call play() which re-uses the last-queued utterance on supported
    // platforms. On others it will re-start.
    if (_state != TtsPlayState.paused) return;
    final result = await _tts.continueText();
    if (result == 1) {
      _state = TtsPlayState.playing;
      notifyListeners();
    }
  }

  /// Fully stop TTS and reset state.
  Future<void> stop() async {
    await _tts.stop();
    _state       = TtsPlayState.idle;
    _articleId   = null;
    _articleTitle = '';
    _wordsSpoken = 0;
    _totalWords  = 0;
    notifyListeners();
  }

  /// Change playback speed.  If currently playing, restart at the new rate.
  Future<void> setSpeed(TtsSpeed speed) async {
    _speed = speed;
    await _tts.setSpeechRate(speed.rate);
    notifyListeners();
  }

  @override
  void dispose() {
    _tts.stop();
    super.dispose();
  }
}
