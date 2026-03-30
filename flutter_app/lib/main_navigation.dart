import 'package:flutter/material.dart';
import 'presentation/screens/home/home_screen.dart';
import 'presentation/screens/search/search_screen.dart';
import 'presentation/screens/bookmarks/bookmarks_screen.dart';
import 'presentation/screens/settings/settings_screen.dart';
import 'core/constants/app_strings.dart';

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
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _currentIndex,
        onTap: (i) => setState(() => _currentIndex = i),
        items: const [
          BottomNavigationBarItem(
            icon:       Icon(Icons.home_outlined),
            activeIcon: Icon(Icons.home_rounded),
            label:      AppStrings.navHome,
          ),
          BottomNavigationBarItem(
            icon:       Icon(Icons.search_outlined),
            activeIcon: Icon(Icons.search_rounded),
            label:      AppStrings.navSearch,
          ),
          BottomNavigationBarItem(
            icon:       Icon(Icons.bookmark_border_rounded),
            activeIcon: Icon(Icons.bookmark_rounded),
            label:      AppStrings.navBookmarks,
          ),
          BottomNavigationBarItem(
            icon:       Icon(Icons.settings_outlined),
            activeIcon: Icon(Icons.settings_rounded),
            label:      AppStrings.navSettings,
          ),
        ],
      ),
    );
  }
}

