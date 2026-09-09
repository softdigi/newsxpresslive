import 'package:flutter/material.dart';

/// Brand & semantic colour palette for NewsXpressLive.
class AppColors {
  AppColors._();

  // ── Brand ─────────────────────────────────────────────────────────────
  static const Color primary    = Color(0xFFE50914); // Netflix-style red
  static const Color primaryDark= Color(0xFFB0000D);
  static const Color accent     = Color(0xFFF5A623); // amber accent

  // ── Backgrounds ───────────────────────────────────────────────────────
  static const Color scaffoldLight = Color(0xFFF5F5F5);
  static const Color scaffoldDark  = Color(0xFF12121F);  // spec: 0xFF12121F
  static const Color cardLight     = Color(0xFFFFFFFF);
  static const Color cardDark      = Color(0xFF1E1E2E);  // spec: 0xFF1E1E2E
  static const Color surfaceDark   = Color(0xFF1E1E2E);  // spec surface

  // ── Dark mode accent colours (spec-exact) ────────────────────────────
  static const Color darkPrimary   = Color(0xFF4A90D9);
  static const Color darkSecondary = Color(0xFF1D9E75);
  static const Color darkError     = Color(0xFFE24B4A);

  // ── Text ──────────────────────────────────────────────────────────────
  static const Color textPrimaryLight   = Color(0xFF1A1A1A);
  static const Color textSecondaryLight = Color(0xFF6B6B6B);
  static const Color textPrimaryDark    = Color(0xFFE2E2E2);  // spec
  static const Color textSecondaryDark  = Color(0xFF9E9EA0);

  // ── Breaking badge ────────────────────────────────────────────────────
  static const Color breakingBadge = Color(0xFFE50914);

  // ── Category chip ─────────────────────────────────────────────────────
  static const Color chipBackground = Color(0xFFFFEBEB);
  static const Color chipText       = Color(0xFFE50914);

  // ── Misc ──────────────────────────────────────────────────────────────
  static const Color divider        = Color(0xFFEEEEEE);
  static const Color shimmerBase    = Color(0xFFE0E0E0);
  static const Color shimmerHighlight = Color(0xFFF5F5F5);

  // ── Dark-mode shimmer & placeholder ───────────────────────────────────
  static const Color shimmerBaseDark      = Color(0xFF424242);   // grey[800]
  static const Color shimmerHighlightDark = Color(0xFF616161);   // grey[700]
  static const Color imagePlaceholderDark = Color(0xFF2A2A3E);
}
