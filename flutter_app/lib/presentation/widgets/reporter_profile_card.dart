import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../../core/constants/app_colors.dart';
import '../../core/constants/app_strings.dart';
import '../../core/utils/string_utils.dart';

/// Displays the reporter/author profile card at the bottom of an article.
///
/// Shows avatar (photo or initials), reporter name, and agency name.
/// If [onMoreByTap] is provided a "More by …" link is shown.
class ReporterProfileCard extends StatelessWidget {
  const ReporterProfileCard({
    super.key,
    required this.reporterName,
    this.reporterPhoto,
    this.agencyName,
    this.onMoreByTap,
  });

  final String   reporterName;
  final String?  reporterPhoto;
  final String?  agencyName;
  final VoidCallback? onMoreByTap;

  @override
  Widget build(BuildContext context) {
    final theme  = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Container(
      padding:    const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color:        isDark
            ? AppColors.surfaceDark
            : AppColors.primary.withOpacity(0.04),
        borderRadius: BorderRadius.circular(12),
        border:       Border.all(
          color: isDark
              ? Colors.white12
              : AppColors.primary.withOpacity(0.12),
        ),
      ),
      child: Row(
        children: [
          // Avatar
          _ReporterAvatar(
            name:  reporterName,
            photo: reporterPhoto,
          ),
          const SizedBox(width: 12),

          // Info
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  AppStrings.reportedBy,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: Colors.grey),
                ),
                const SizedBox(height: 2),
                Text(
                  reporterName,
                  style: theme.textTheme.titleMedium
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                if (agencyName != null && agencyName!.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Row(
                    children: [
                      const Icon(Icons.business_rounded,
                          size: 12, color: Colors.grey),
                      const SizedBox(width: 4),
                      Flexible(
                        child: Text(
                          agencyName!,
                          style: theme.textTheme.bodySmall
                              ?.copyWith(color: Colors.grey),
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),

          // More by
          if (onMoreByTap != null)
            TextButton(
              onPressed: onMoreByTap,
              style: TextButton.styleFrom(
                  foregroundColor: AppColors.primary,
                  padding: const EdgeInsets.symmetric(
                      horizontal: 8, vertical: 4)),
              child: const Text(AppStrings.moreByReporter,
                  style: TextStyle(fontSize: 12)),
            ),
        ],
      ),
    );
  }
}

// ── Avatar ─────────────────────────────────────────────────────────────────

class _ReporterAvatar extends StatelessWidget {
  const _ReporterAvatar({required this.name, this.photo});

  final String  name;
  final String? photo;

  @override
  Widget build(BuildContext context) {
    if (photo != null && photo!.isNotEmpty) {
      return CircleAvatar(
        radius: 26,
        backgroundImage:
            CachedNetworkImageProvider(photo!),
        backgroundColor: AppColors.primary.withOpacity(0.1),
      );
    }
    return CircleAvatar(
      radius: 26,
      backgroundColor: AppColors.primary.withOpacity(0.15),
      child: Text(
        StringUtils.initials(name),
        style: const TextStyle(
          color:      AppColors.primary,
          fontSize:   16,
          fontWeight: FontWeight.bold,
        ),
      ),
    );
  }
}
