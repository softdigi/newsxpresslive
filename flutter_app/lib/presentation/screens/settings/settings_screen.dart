import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../providers/theme_provider.dart';
import '../../../core/constants/app_strings.dart';
import '../../../core/constants/app_colors.dart';

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
          _section('Appearance'),
          SwitchListTile(
            title: const Text(AppStrings.darkMode),
            subtitle: const Text('Switch between light and dark theme'),
            value:    theme.isDark,
            onChanged: (_) => theme.toggle(),
            activeColor: AppColors.primary,
            secondary: Icon(
              theme.isDark
                  ? Icons.dark_mode_rounded
                  : Icons.light_mode_rounded,
            ),
          ),
          const Divider(),
          _section('About'),
          ListTile(
            leading: const Icon(Icons.info_outline_rounded),
            title:   const Text(AppStrings.about),
            subtitle: const Text(AppStrings.appName),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => _showAbout(context),
          ),
          ListTile(
            leading: const Icon(Icons.privacy_tip_outlined),
            title:   const Text(AppStrings.privacyPolicy),
            trailing: const Icon(Icons.chevron_right),
            onTap: () {},
          ),
          ListTile(
            leading: const Icon(Icons.new_releases_outlined),
            title:   const Text(AppStrings.version),
            trailing: const Text(AppStrings.appVersion,
                style: TextStyle(color: Colors.grey)),
          ),
        ],
      ),
    );
  }

  Widget _section(String title) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
    child: Text(title.toUpperCase(),
        style: const TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w700,
            letterSpacing: 1.2,
            color: AppColors.primary)),
  );

  void _showAbout(BuildContext context) {
    showAboutDialog(
      context: context,
      applicationName: AppStrings.appName,
      applicationVersion: AppStrings.appVersion,
      applicationLegalese: '© 2026 NewsXpressLive. All rights reserved.',
    );
  }
}
