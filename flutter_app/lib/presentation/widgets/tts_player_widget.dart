import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/tts_provider.dart';
import '../../core/constants/app_colors.dart';
import '../../core/constants/app_strings.dart';

/// Inline audio player shown inside [ArticleDetailScreen] while TTS is active.
///
/// Displays:
///  - Article title being read
///  - Progress bar (word-level)
///  - Play / Pause button
///  - Stop button
///  - Speed selector
class TtsPlayerWidget extends StatelessWidget {
  const TtsPlayerWidget({super.key});

  @override
  Widget build(BuildContext context) {
    return Consumer<TtsProvider>(
      builder: (context, tts, _) {
        if (tts.isIdle) return const SizedBox.shrink();

        final theme = Theme.of(context);
        final isDark = theme.brightness == Brightness.dark;
        final bg     = isDark ? AppColors.cardDark : AppColors.cardLight;

        return AnimatedContainer(
          duration: const Duration(milliseconds: 250),
          curve:    Curves.easeInOut,
          margin:   const EdgeInsets.symmetric(vertical: 12),
          decoration: BoxDecoration(
            color:        bg,
            borderRadius: BorderRadius.circular(12),
            boxShadow: [
              BoxShadow(
                color:       Colors.black.withOpacity(isDark ? 0.35 : 0.10),
                blurRadius:  8,
                offset:      const Offset(0, 2),
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ── Header ───────────────────────────────────────────────
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 8, 4),
                child: Row(
                  children: [
                    const Icon(Icons.headphones_rounded,
                        size: 16, color: AppColors.primary),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        tts.articleTitle.isNotEmpty
                            ? tts.articleTitle
                            : AppStrings.ttsListening,
                        maxLines:  1,
                        overflow:  TextOverflow.ellipsis,
                        style: theme.textTheme.labelMedium?.copyWith(
                          color: AppColors.primary,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                    // Stop button
                    IconButton(
                      icon:     const Icon(Icons.close_rounded, size: 18),
                      tooltip:  AppStrings.ttsStop,
                      padding:  EdgeInsets.zero,
                      constraints: const BoxConstraints(),
                      onPressed: tts.stop,
                    ),
                  ],
                ),
              ),

              // ── Progress bar ─────────────────────────────────────────
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 14),
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value:            tts.isLoading ? null : tts.progress,
                    backgroundColor:  AppColors.divider,
                    color:            AppColors.primary,
                    minHeight:        4,
                  ),
                ),
              ),
              const SizedBox(height: 8),

              // ── Controls ─────────────────────────────────────────────
              Padding(
                padding: const EdgeInsets.fromLTRB(8, 0, 8, 10),
                child: Row(
                  children: [
                    // Play / Pause
                    _PlayPauseButton(tts: tts),

                    const SizedBox(width: 4),

                    // State label
                    Text(
                      _stateLabel(tts),
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: Colors.grey,
                      ),
                    ),

                    const Spacer(),

                    // Speed selector
                    _SpeedSelector(tts: tts),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  String _stateLabel(TtsProvider tts) {
    if (tts.isLoading) return AppStrings.ttsLoading;
    if (tts.isPaused)  return AppStrings.ttsPaused;
    if (tts.isPlaying) return AppStrings.ttsPlaying;
    return '';
  }
}

// ── Play / Pause button ───────────────────────────────────────────────────────

class _PlayPauseButton extends StatelessWidget {
  const _PlayPauseButton({required this.tts});

  final TtsProvider tts;

  @override
  Widget build(BuildContext context) {
    IconData icon;
    VoidCallback? onTap;

    if (tts.isLoading) {
      icon  = Icons.play_arrow_rounded;
      onTap = null;
    } else if (tts.isPlaying) {
      icon  = Icons.pause_rounded;
      onTap = tts.pause;
    } else {
      // paused
      icon  = Icons.play_arrow_rounded;
      onTap = tts.resume;
    }

    return IconButton(
      icon: tts.isLoading
          ? const SizedBox(
              width:  20,
              height: 20,
              child:  CircularProgressIndicator(
                  strokeWidth: 2, color: AppColors.primary),
            )
          : Icon(icon, color: AppColors.primary, size: 28),
      tooltip:    tts.isPlaying ? AppStrings.ttsPause : AppStrings.ttsResume,
      onPressed:  onTap,
      visualDensity: VisualDensity.compact,
    );
  }
}

// ── Speed selector ────────────────────────────────────────────────────────────

class _SpeedSelector extends StatelessWidget {
  const _SpeedSelector({required this.tts});

  final TtsProvider tts;

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<TtsSpeed>(
      initialValue: tts.speed,
      tooltip:      AppStrings.ttsSpeed,
      padding:      EdgeInsets.zero,
      shape:        RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(10)),
      onSelected:   tts.setSpeed,
      itemBuilder:  (_) => TtsSpeed.all
          .map(
            (s) => PopupMenuItem<TtsSpeed>(
              value: s,
              child: Row(
                children: [
                  if (s == tts.speed)
                    const Icon(Icons.check, size: 16, color: AppColors.primary)
                  else
                    const SizedBox(width: 16),
                  const SizedBox(width: 8),
                  Text(s.label),
                ],
              ),
            ),
          )
          .toList(),
      child: Chip(
        label:           Text(tts.speed.label),
        labelStyle:      const TextStyle(
            fontSize: 12, fontWeight: FontWeight.w600),
        padding:         const EdgeInsets.symmetric(horizontal: 4),
        backgroundColor: AppColors.primary.withOpacity(0.10),
        side:            BorderSide.none,
        visualDensity:   VisualDensity.compact,
      ),
    );
  }
}
