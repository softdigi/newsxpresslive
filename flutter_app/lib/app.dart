import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'core/theme/app_theme.dart';
import 'core/constants/app_strings.dart';
import 'providers/news_provider.dart';
import 'providers/theme_provider.dart';
import 'providers/bookmark_provider.dart';
import 'main_navigation.dart';

/// Root widget: sets up providers and theming.
class NewsXpressApp extends StatelessWidget {
  const NewsXpressApp({
    super.key,
    required this.themeProvider,
    required this.bookmarkProvider,
  });

  final ThemeProvider    themeProvider;
  final BookmarkProvider bookmarkProvider;

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: themeProvider),
        ChangeNotifierProvider.value(value: bookmarkProvider),
        ChangeNotifierProvider(create: (_) => NewsProvider()),
      ],
      child: Consumer<ThemeProvider>(
        builder: (context, theme, child) => MaterialApp(
          title:                      AppStrings.appName,
          debugShowCheckedModeBanner: false,
          theme:                      AppTheme.light,
          darkTheme:                  AppTheme.dark,
          themeMode:                  theme.themeMode,
          home:                       child,
        ),
        child: MainNavigation(key: mainNavKey),
      ),
    );
  }
}

