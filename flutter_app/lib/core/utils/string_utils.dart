/// General string utility helpers.
class StringUtils {
  StringUtils._();

  /// Strip all HTML tags from a string.
  static String stripHtml(String html) {
    return html.replaceAll(RegExp(r'<[^>]*>'), ' ')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();
  }

  /// Truncate text to [maxLength] chars, appending '…' if needed.
  static String truncate(String text, int maxLength) {
    if (text.length <= maxLength) return text;
    return '${text.substring(0, maxLength)}…';
  }

  /// Estimate reading time in minutes (200 wpm average).
  static int readingMinutes(String html) {
    final words = stripHtml(html).split(RegExp(r'\s+')).length;
    return (words / 200).ceil().clamp(1, 99);
  }

  /// Capitalise first letter.
  static String capitalize(String s) {
    if (s.isEmpty) return s;
    return s[0].toUpperCase() + s.substring(1);
  }

  /// Format large view counts: 1200 → "1.2K".
  static String formatViews(int n) {
    if (n >= 1000000) return '${(n / 1000000).toStringAsFixed(1)}M';
    if (n >= 1000) return '${(n / 1000).toStringAsFixed(1)}K';
    return n.toString();
  }

  /// Returns the initials for a name (up to 2 chars).
  static String initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts[0][0].toUpperCase();
    return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
  }
}
