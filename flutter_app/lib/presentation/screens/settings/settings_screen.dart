import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../providers/theme_provider.dart';
import '../../../core/constants/app_strings.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/api_endpoints.dart';

/// Settings / preferences screen.
class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = context.watch<ThemeProvider>();

    return Scaffold(
      appBar: AppBar(title: const Text(AppStrings.navSettings)),
      body: ListView(
        children: [
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
              Uri.parse('https://play.google.com/store/apps/details?id=com.newsxpresslive'),
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
