import 'package:timeago/timeago.dart' as timeago;

/// Utility helpers for formatting dates and timestamps.
class DateFormatter {
  DateFormatter._();

  /// Returns relative time: "2 hours ago", "3 days ago".
  static String timeAgo(String? dateString) {
    if (dateString == null || dateString.isEmpty) return '';
    try {
      final dt = DateTime.parse(dateString);
      return timeago.format(dt);
    } catch (_) {
      return dateString;
    }
  }

  /// Returns formatted date: "Mar 28, 2026".
  static String formatDate(String? dateString, {String format = 'MMM d, yyyy'}) {
    if (dateString == null || dateString.isEmpty) return '';
    try {
      final dt = DateTime.parse(dateString);
      return _formatDt(dt);
    } catch (_) {
      return dateString ?? '';
    }
  }

  static String _formatDt(DateTime dt) {
    const months = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];
    return '${months[dt.month - 1]} ${dt.day}, ${dt.year}';
  }

  /// Returns time: "3:45 PM".
  static String formatTime(String? dateString) {
    if (dateString == null || dateString.isEmpty) return '';
    try {
      final dt = DateTime.parse(dateString);
      final h = dt.hour > 12 ? dt.hour - 12 : (dt.hour == 0 ? 12 : dt.hour);
      final m = dt.minute.toString().padLeft(2, '0');
      final ampm = dt.hour >= 12 ? 'PM' : 'AM';
      return '$h:$m $ampm';
    } catch (_) {
      return '';
    }
  }
}
