import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../constants/app_colors.dart';

/// Central theme configuration for NewsXpressLive.
class AppTheme {
  AppTheme._();

  static TextTheme _buildTextTheme(Color primaryText, Color secondaryText) {
    return GoogleFonts.notoSansTextTheme(
      TextTheme(
        displayLarge:  TextStyle(color: primaryText, fontWeight: FontWeight.bold),
        displayMedium: TextStyle(color: primaryText, fontWeight: FontWeight.bold),
        headlineLarge: TextStyle(color: primaryText, fontWeight: FontWeight.w700, fontSize: 22),
        headlineMedium:TextStyle(color: primaryText, fontWeight: FontWeight.w600, fontSize: 18),
        titleLarge:    TextStyle(color: primaryText, fontWeight: FontWeight.w600, fontSize: 16),
        titleMedium:   TextStyle(color: primaryText, fontWeight: FontWeight.w500, fontSize: 15),
        bodyLarge:     TextStyle(color: primaryText, fontSize: 15, height: 1.6),
        bodyMedium:    TextStyle(color: primaryText, fontSize: 14, height: 1.5),
        bodySmall:     TextStyle(color: secondaryText, fontSize: 12),
        labelSmall:    TextStyle(color: secondaryText, fontSize: 11, letterSpacing: 0.5),
      ),
    );
  }

  // ── Light Theme ───────────────────────────────────────────────────────
  static ThemeData get light => ThemeData(
    useMaterial3: true,
    brightness: Brightness.light,
    colorScheme: ColorScheme.light(
      primary:   AppColors.primary,
      secondary: AppColors.accent,
      surface:   AppColors.cardLight,
    ),
    scaffoldBackgroundColor: AppColors.scaffoldLight,
    appBarTheme: AppBarTheme(
      backgroundColor: Colors.white,
      foregroundColor: AppColors.textPrimaryLight,
      elevation: 0,
      centerTitle: false,
      titleTextStyle: GoogleFonts.notoSans(
        color: AppColors.textPrimaryLight,
        fontSize: 20,
        fontWeight: FontWeight.w700,
      ),
    ),
    cardTheme: CardThemeData(
      color: AppColors.cardLight,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      margin: EdgeInsets.zero,
    ),
    dividerTheme: const DividerThemeData(color: AppColors.divider, thickness: 1),
    textTheme: _buildTextTheme(
      AppColors.textPrimaryLight,
      AppColors.textSecondaryLight,
    ),
    inputDecorationTheme: InputDecorationTheme(
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: AppColors.divider),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: AppColors.divider),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: AppColors.primary, width: 1.5),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
      ),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: AppColors.chipBackground,
      labelStyle: const TextStyle(color: AppColors.chipText, fontSize: 12, fontWeight: FontWeight.w600),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      side: BorderSide.none,
    ),
    bottomNavigationBarTheme: const BottomNavigationBarThemeData(
      backgroundColor: Colors.white,
      selectedItemColor: AppColors.primary,
      unselectedItemColor: Color(0xFF9E9E9E),
      type: BottomNavigationBarType.fixed,
      elevation: 8,
    ),
  );

  // ── Dark Theme ────────────────────────────────────────────────────────
  static ThemeData get dark => ThemeData(
    useMaterial3: true,
    brightness: Brightness.dark,
    colorScheme: const ColorScheme.dark(
      primary:      Color(0xFF4A90D9),
      secondary:    Color(0xFF1D9E75),
      surface:      Color(0xFF1E1E2E),
      error:        Color(0xFFE24B4A),
      onPrimary:    Colors.white,
      onSecondary:  Colors.white,
      onSurface:    Color(0xFFE2E2E2),
    ),
    scaffoldBackgroundColor: const Color(0xFF12121F),
    cardTheme: CardThemeData(
      color: const Color(0xFF1E1E2E),
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      margin: EdgeInsets.zero,
    ),
    appBarTheme: AppBarTheme(
      backgroundColor: const Color(0xFF1E1E2E),
      foregroundColor: const Color(0xFFE2E2E2),
      elevation: 0,
      centerTitle: false,
      titleTextStyle: GoogleFonts.notoSans(
        color: const Color(0xFFE2E2E2),
        fontSize: 20,
        fontWeight: FontWeight.w700,
      ),
    ),
    dividerTheme: const DividerThemeData(color: Color(0xFF2A2A3E), thickness: 1),
    textTheme: _buildTextTheme(
      const Color(0xFFE2E2E2),
      const Color(0xFF9E9EA0),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: const Color(0xFF1E1E2E),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: BorderSide.none,
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: BorderSide.none,
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Color(0xFF4A90D9), width: 1.5),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: const Color(0xFF4A90D9),
        foregroundColor: Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
      ),
    ),
    chipTheme: const ChipThemeData(
      backgroundColor: Color(0xFF1E1E2E),
      labelStyle: TextStyle(color: Color(0xFF1D9E75), fontSize: 12, fontWeight: FontWeight.w600),
      padding: EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      side: BorderSide.none,
    ),
    bottomNavigationBarTheme: const BottomNavigationBarThemeData(
      backgroundColor: Color(0xFF1E1E2E),
      selectedItemColor: Color(0xFF4A90D9),
      unselectedItemColor: Color(0xFF888780),
      type: BottomNavigationBarType.fixed,
      elevation: 8,
    ),
  );
}
