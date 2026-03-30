import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../providers/theme_provider.dart';
import '../../../providers/auth_provider.dart';
import '../../../providers/feature_flags_provider.dart';
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
    final theme = context.watch<ThemeProvider>();
    final auth  = context.watch<AuthProvider>();
    final flags = context.watch<FeatureFlagsProvider>();

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

          // ── Appearance ──────────────────────────────────────────────
          _section('Appearance'),
          _themeRadio(context, theme, ThemeMode.system,
              Icons.brightness_auto_rounded, AppStrings.themeSystem),
          _themeRadio(context, theme, ThemeMode.light,
              Icons.light_mode_rounded, AppStrings.themeLight),
          _themeRadio(context, theme, ThemeMode.dark,
              Icons.dark_mode_rounded, AppStrings.themeDark),

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
