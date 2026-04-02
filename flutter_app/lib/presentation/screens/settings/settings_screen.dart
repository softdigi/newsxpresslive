import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../providers/theme_provider.dart';
import '../../../providers/auth_provider.dart';
import '../../../providers/feature_flags_provider.dart';
import '../../../providers/notification_provider.dart';
import '../../../data/services/feature_flags_service.dart';
import '../../../core/constants/app_strings.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/api_endpoints.dart';
import '../reporter/submit_news_screen.dart';

/// Settings / preferences screen.
class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme  = context.watch<ThemeProvider>();
    final auth   = context.watch<AuthProvider>();
    final flags  = context.watch<FeatureFlagsProvider>();
    final notif  = context.watch<NotificationProvider>();

    return Scaffold(
      appBar: AppBar(title: const Text(AppStrings.navSettings)),
      body: ListView(
        children: [
          // ── Account ─────────────────────────────────────────────────
          _section('Account'),
          _AccountTile(auth: auth),

          // ── Reporter Mode ────────────────────────────────────────────
          if (flags.isEnabled(FeatureFlag.reporterMode)) ...[
            const Divider(),
            _section('Reporter'),
            ListTile(
              leading: const Icon(Icons.edit_note_rounded,
                  color: AppColors.primary),
              title: const Text(AppStrings.submitNews),
              subtitle: const Text('Submit a news story for review'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (_) => const SubmitNewsScreen()),
              ),
            ),
          ],

          const Divider(),

          // ── Notifications ────────────────────────────────────────────
          _section(AppStrings.notifSectionTitle),
          _NotificationSettingsSection(notif: notif),

          const Divider(),

          // ── Appearance ──────────────────────────────────────────────
          _section('Appearance'),
          _themeRadio(context, theme, ThemeMode.system,
              Icons.brightness_auto_rounded, AppStrings.themeSystem),
          _themeRadio(context, theme, ThemeMode.light,
              Icons.light_mode_rounded, AppStrings.themeLight),
          _themeRadio(context, theme, ThemeMode.dark,
              Icons.dark_mode_rounded, AppStrings.themeDark),
          // ── Auto dark mode by time ─────────────────────────────────
          SwitchListTile(
            secondary: Icon(
              Icons.schedule_rounded,
              color: theme.isAutoByTime ? AppColors.primary : null,
            ),
            title: const Text('Auto Dark Mode (Time-based)'),
            subtitle: const Text('Dark 8 PM – 7 AM, Light otherwise'),
            value: theme.isAutoByTime,
            activeColor: AppColors.primary,
            onChanged: (v) => theme.setAutoByTime(v),
          ),

          const Divider(),

          // ── Reading ─────────────────────────────────────────────────
          _section('Reading'),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(Icons.text_fields_rounded,
                        size: 20, color: AppColors.primary),
                    const SizedBox(width: 12),
                    Text('Text Size',
                        style: Theme.of(context).textTheme.bodyLarge),
                  ],
                ),
                const SizedBox(height: 8),
                _fontSizeChips(context, theme),
              ],
            ),
          ),

          const Divider(),

          // ── App ─────────────────────────────────────────────────────
          _section('App'),
          ListTile(
            leading: const Icon(Icons.share_outlined),
            title: const Text('Share App'),
            subtitle: const Text('Tell your friends about NewsXpressLive'),
            onTap: () => Share.share(
              '📰 Get NewsXpressLive – Breaking News, Latest Updates!\n'
              '${ApiEndpoints.baseUrl}',
            ),
          ),
          ListTile(
            leading: const Icon(Icons.star_outline_rounded),
            title: const Text('Rate the App'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => launchUrl(
              Uri.parse(
                  'https://play.google.com/store/apps/details?id=com.newsxpresslive'),
              mode: LaunchMode.externalApplication,
            ),
          ),

          const Divider(),

          // ── About ───────────────────────────────────────────────────
          _section('About'),
          ListTile(
            leading: const Icon(Icons.info_outline_rounded),
            title: const Text(AppStrings.about),
            subtitle: const Text(AppStrings.appName),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => _showAbout(context),
          ),
          ListTile(
            leading: const Icon(Icons.privacy_tip_outlined),
            title: const Text(AppStrings.privacyPolicy),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => launchUrl(
              Uri.parse('${ApiEndpoints.baseUrl}/privacy'),
              mode: LaunchMode.externalApplication,
            ),
          ),
          ListTile(
            leading: const Icon(Icons.new_releases_outlined),
            title: const Text(AppStrings.version),
            trailing: const Text(AppStrings.appVersion,
                style: TextStyle(color: Colors.grey)),
          ),
        ],
      ),
    );
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  Widget _section(String title) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
    child: Text(title.toUpperCase(),
        style: const TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w700,
            letterSpacing: 1.2,
            color: AppColors.primary)),
  );

  Widget _themeRadio(BuildContext context, ThemeProvider theme,
      ThemeMode mode, IconData icon, String label) {
    final selected = theme.themeMode == mode;
    return RadioListTile<ThemeMode>(
      value:    mode,
      groupValue: theme.themeMode,
      activeColor: AppColors.primary,
      onChanged: (_) => theme.setThemeMode(mode),
      title: Text(label),
      secondary: Icon(icon,
          color: selected ? AppColors.primary : null),
    );
  }

  Widget _fontSizeChips(BuildContext context, ThemeProvider theme) {
    const sizes = [
      ('Small',  0.9),
      ('Medium', 1.0),
      ('Large',  1.15),
    ];
    return Wrap(
      spacing: 8,
      children: sizes.map((entry) {
        final (label, scale) = entry;
        final selected = (theme.fontScale - scale).abs() < 0.01;
        return ChoiceChip(
          label: Text(label),
          selected: selected,
          selectedColor: AppColors.primary,
          labelStyle: TextStyle(
            color: selected ? Colors.white : null,
            fontWeight: FontWeight.w600,
          ),
          onSelected: (_) => theme.setFontScale(scale),
        );
      }).toList(),
    );
  }

  void _showAbout(BuildContext context) {
    showAboutDialog(
      context: context,
      applicationName: AppStrings.appName,
      applicationVersion: AppStrings.appVersion,
      applicationLegalese: '© 2026 NewsXpressLive. All rights reserved.',
    );
  }
}

// ── Account tile ──────────────────────────────────────────────────────────

class _AccountTile extends StatelessWidget {
  const _AccountTile({required this.auth});

  final AuthProvider auth;

  @override
  Widget build(BuildContext context) {
    final user   = auth.user;
    final isGuest = auth.isGuest;

    if (user == null || isGuest) {
      return ListTile(
        leading: const Icon(Icons.account_circle_outlined),
        title: const Text(AppStrings.guestUser),
        subtitle: const Text('Sign in for personalised news'),
        trailing: TextButton(
          onPressed: () => auth.signInWithGoogle(),
          child: const Text(AppStrings.signIn,
              style: TextStyle(color: AppColors.primary)),
        ),
      );
    }

    return ListTile(
      leading: user.photoUrl != null
          ? CircleAvatar(
              backgroundImage: NetworkImage(user.photoUrl!),
              radius: 18,
            )
          : const CircleAvatar(
              radius: 18,
              backgroundColor: AppColors.primary,
              child: Icon(Icons.person, color: Colors.white, size: 18),
            ),
      title: Text(user.name ?? 'User',
          style: const TextStyle(fontWeight: FontWeight.w600)),
      subtitle: Text(user.email ?? user.firebaseUid,
          style: const TextStyle(fontSize: 12)),
      trailing: TextButton(
        onPressed: () async {
          final confirm = await showDialog<bool>(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text('Sign Out'),
              content: const Text('Are you sure you want to sign out?'),
              actions: [
                TextButton(
                    onPressed: () => Navigator.pop(ctx, false),
                    child: const Text(AppStrings.cancel)),
                TextButton(
                    onPressed: () => Navigator.pop(ctx, true),
                    child: const Text(AppStrings.signOut,
                        style: TextStyle(color: Colors.red))),
              ],
            ),
          );
          if (confirm == true && context.mounted) {
            context.read<AuthProvider>().signOut();
          }
        },
        child: const Text(AppStrings.signOut,
            style: TextStyle(color: Colors.red)),
      ),
    );
  }
}

// ── Notification settings section ────────────────────────────────────────────

class _NotificationSettingsSection extends StatelessWidget {
  const _NotificationSettingsSection({required this.notif});

  final NotificationProvider notif;

  @override
  Widget build(BuildContext context) {
    final bestHours = notif.bestHours;
    final cats      = notif.interestCategories;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // ── Interest filter ────────────────────────────────────────────
        SwitchListTile(
          secondary: Icon(
            Icons.category_rounded,
            color: notif.interestFilter ? AppColors.primary : null,
          ),
          title: const Text(AppStrings.notifInterestFilter),
          subtitle: const Text(AppStrings.notifInterestFilterSub),
          value: notif.interestFilter,
          activeColor: AppColors.primary,
          onChanged: (v) => notif.setInterestFilter(v),
        ),

        // ── Breaking alerts ────────────────────────────────────────────
        SwitchListTile(
          secondary: Icon(
            Icons.notification_important_rounded,
            color: notif.breakingAlerts ? AppColors.primary : null,
          ),
          title: const Text(AppStrings.notifBreakingAlerts),
          subtitle: const Text(AppStrings.notifBreakingAlertsSub),
          value: notif.breakingAlerts,
          activeColor: AppColors.primary,
          onChanged: (v) => notif.setBreakingAlerts(v),
        ),

        // ── Quiet hours ────────────────────────────────────────────────
        ListTile(
          leading: Icon(
            Icons.bedtime_rounded,
            color: AppColors.primary.withOpacity(0.8),
          ),
          title: const Text(AppStrings.notifQuietHours),
          subtitle: Text(
            notif.quietStart == notif.quietEnd
                ? 'Disabled'
                : '${_fmtHour(notif.quietStart)} – ${_fmtHour(notif.quietEnd)}',
          ),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => _showQuietHoursPicker(context),
        ),

        // ── Frequency (read-only) ──────────────────────────────────────
        ListTile(
          leading: const Icon(Icons.notifications_active_outlined,
              color: AppColors.primary),
          title: const Text(AppStrings.notifLast24h),
          trailing: Text(
            '${notif.notificationsLast24h}',
            style: const TextStyle(
              fontWeight: FontWeight.w600,
              color: AppColors.primary,
            ),
          ),
        ),

        // ── Best engagement hours ──────────────────────────────────────
        ListTile(
          leading: const Icon(Icons.access_time_rounded,
              color: AppColors.primary),
          title: const Text(AppStrings.notifBestTime),
          subtitle: Text(
            bestHours.isEmpty
                ? AppStrings.notifBestTimeNone
                : bestHours.map(_fmtHour).join('  •  '),
          ),
        ),

        // ── Interest categories (read-only) ────────────────────────────
        ListTile(
          leading: const Icon(Icons.interests_rounded,
              color: AppColors.primary),
          title: const Text(AppStrings.notifCategories),
          subtitle: Text(
            cats.isEmpty
                ? AppStrings.notifCategoriesNone
                : cats.join(', '),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }

  // ── Quiet-hours bottom sheet ───────────────────────────────────────────

  Future<void> _showQuietHoursPicker(BuildContext context) async {
    int start = notif.quietStart;
    int end   = notif.quietEnd;

    await showModalBottomSheet<void>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => Padding(
          padding: const EdgeInsets.fromLTRB(24, 20, 24, 32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                AppStrings.notifQuietHours,
                style: Theme.of(ctx).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 4),
              const Text(
                'Set equal values to disable quiet hours.',
                style: TextStyle(fontSize: 12, color: Colors.grey),
              ),
              const SizedBox(height: 20),

              // Start hour
              _HourSlider(
                label: 'Start',
                value: start,
                onChanged: (v) => setModalState(() => start = v),
              ),
              const SizedBox(height: 12),

              // End hour
              _HourSlider(
                label: 'End',
                value: end,
                onChanged: (v) => setModalState(() => end = v),
              ),
              const SizedBox(height: 20),

              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () {
                    notif.setQuietHours(start, end);
                    Navigator.pop(ctx);
                  },
                  child: const Text('Save'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  static String _fmtHour(int h) {
    final period = h < 12 ? 'AM' : 'PM';
    final display = h == 0 ? 12 : (h > 12 ? h - 12 : h);
    return '$display $period';
  }
}

// ── Hour slider helper ────────────────────────────────────────────────────────

class _HourSlider extends StatelessWidget {
  const _HourSlider({
    required this.label,
    required this.value,
    required this.onChanged,
  });

  final String        label;
  final int           value;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    final period  = value < 12 ? 'AM' : 'PM';
    final display = value == 0 ? 12 : (value > 12 ? value - 12 : value);
    return Row(
      children: [
        SizedBox(
          width: 60,
          child: Text(label,
              style: const TextStyle(fontWeight: FontWeight.w600)),
        ),
        Expanded(
          child: Slider(
            value:    value.toDouble(),
            min:      0,
            max:      23,
            divisions: 23,
            activeColor: AppColors.primary,
            label: '$display $period',
            onChanged: (v) => onChanged(v.round()),
          ),
        ),
        SizedBox(
          width: 52,
          child: Text(
            '$display $period',
            style: const TextStyle(
                fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ),
      ],
    );
  }
}

