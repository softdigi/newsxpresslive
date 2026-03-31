import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'providers/bookmark_provider.dart';
import 'providers/offline_provider.dart';
import 'presentation/screens/home/home_screen.dart';
import 'presentation/screens/search/search_screen.dart';
import 'presentation/screens/bookmarks/bookmarks_screen.dart';
import 'presentation/screens/offline/offline_screen.dart';
import 'presentation/screens/settings/settings_screen.dart';
import 'core/constants/app_strings.dart';
import 'core/constants/app_colors.dart';

/// Main scaffold with BottomNavigationBar.
///
/// Access via [mainNavKey] to switch tabs programmatically:
///   mainNavKey.currentState?.switchTab(1);
final GlobalKey<MainNavigationState> mainNavKey = GlobalKey<MainNavigationState>();

class MainNavigation extends StatefulWidget {
  const MainNavigation({super.key});

  @override
  MainNavigationState createState() => MainNavigationState();
}

class MainNavigationState extends State<MainNavigation> {
  int _currentIndex = 0;

  static const List<Widget> _screens = [
    HomeScreen(),
    SearchScreen(),
    BookmarksScreen(),
    OfflineScreen(),
    SettingsScreen(),
  ];

  /// Switch to tab [index] from anywhere in the app.
  void switchTab(int index) {
    if (index < 0 || index >= _screens.length) return;
    setState(() => _currentIndex = index);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: _screens,
      ),
      bottomNavigationBar: Consumer2<BookmarkProvider, OfflineProvider>(
        builder: (context, bm, offline, _) => BottomNavigationBar(
          currentIndex: _currentIndex,
          onTap: (i) => setState(() => _currentIndex = i),
          items: [
            const BottomNavigationBarItem(
              icon:       Icon(Icons.home_outlined),
              activeIcon: Icon(Icons.home_rounded),
              label:      AppStrings.navHome,
            ),
            const BottomNavigationBarItem(
              icon:       Icon(Icons.search_outlined),
              activeIcon: Icon(Icons.search_rounded),
              label:      AppStrings.navSearch,
            ),
            BottomNavigationBarItem(
              icon:       _bookmarkIcon(bm.count, false),
              activeIcon: _bookmarkIcon(bm.count, true),
              label:      AppStrings.navBookmarks,
            ),
            BottomNavigationBarItem(
              icon:       _offlineIcon(offline.count, false),
              activeIcon: _offlineIcon(offline.count, true),
              label:      AppStrings.navOffline,
            ),
            const BottomNavigationBarItem(
              icon:       Icon(Icons.settings_outlined),
              activeIcon: Icon(Icons.settings_rounded),
              label:      AppStrings.navSettings,
            ),
          ],
        ),
      ),
    );
  }

  Widget _bookmarkIcon(int count, bool active) {
    final icon = Icon(active
        ? Icons.bookmark_rounded
        : Icons.bookmark_border_rounded);
    if (count == 0) return icon;
    return Badge(
      backgroundColor: AppColors.primary,
      label: Text(count > 99 ? '99+' : count.toString(),
          style: const TextStyle(fontSize: 9)),
      child: icon,
    );
  }

  Widget _offlineIcon(int count, bool active) {
    final icon = Icon(active
        ? Icons.download_for_offline_rounded
        : Icons.download_for_offline_outlined);
    if (count == 0) return icon;
    return Badge(
      backgroundColor: AppColors.primary,
      label: Text(count > 99 ? '99+' : count.toString(),
          style: const TextStyle(fontSize: 9)),
      child: icon,
    );
  }
}

