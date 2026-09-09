// flutter_app/lib/core/services/rating_service.dart
//
// RatingService — manages in-app rating prompt logic for NewsXpressLive.
//
// Trigger conditions (ANY one met):
//   1. User reads 5th article in a session
//   2. Reporter's blue tick is approved
//   3. Reporter receives their first payment
//   4. App opened 7th time overall
//
// Rules:
//   - Never shown more than once in 30 days
//   - Never shown if user has already rated
//   - Uses in_app_review package (native Play Store dialog)
//   - Fallback: custom dialog with Play Store deep link

import 'dart:io';
import 'package:flutter/material.dart';
import 'package:in_app_review/in_app_review.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

class RatingService {
  static final RatingService _instance = RatingService._internal();
  factory RatingService() => _instance;
  RatingService._internal();

  static RatingService get instance => _instance;

  // SharedPreferences keys
  static const _kLastShownDate   = 'rating_last_shown_date';
  static const _kHasRated        = 'rating_has_rated';
  static const _kAppOpenCount    = 'rating_app_open_count';
  static const _kSessionReads    = 'rating_session_reads';
  static const _kFirstPaymentDone = 'rating_first_payment_notified';

  static const _minDaysBetweenPrompts = 30;
  static const _appOpensThreshold     = 7;
  static const _sessionReadsThreshold = 5;

  final InAppReview _inAppReview = InAppReview.instance;

  // ── Called on every app launch ─────────────────────────────────────────────
  Future<void> onAppLaunch() async {
    final prefs = await SharedPreferences.getInstance();
    final count = (prefs.getInt(_kAppOpenCount) ?? 0) + 1;
    await prefs.setInt(_kAppOpenCount, count);

    if (count == _appOpensThreshold) {
      // Don't show immediately — let the app finish loading
      await Future.delayed(const Duration(seconds: 3));
    }
  }

  // ── Called when user reads an article (pass BuildContext for fallback) ───
  Future<void> onArticleRead(BuildContext context) async {
    final prefs = await SharedPreferences.getInstance();
    final reads = (prefs.getInt(_kSessionReads) ?? 0) + 1;
    await prefs.setInt(_kSessionReads, reads);

    if (reads >= _sessionReadsThreshold) {
      await _maybeShowPrompt(context, trigger: 'article_reads');
    }
  }

  // ── Called when blue tick is approved ─────────────────────────────────────
  Future<void> onBlueTickApproved(BuildContext context) async {
    await _maybeShowPrompt(context, trigger: 'blue_tick_approved');
  }

  // ── Called when reporter receives first payment ────────────────────────────
  Future<void> onFirstPaymentReceived(BuildContext context) async {
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool(_kFirstPaymentDone) == true) return;
    await prefs.setBool(_kFirstPaymentDone, true);
    await _maybeShowPrompt(context, trigger: 'first_payment');
  }

  // ── Reset session article counter at start of new session ─────────────────
  Future<void> resetSessionReads() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt(_kSessionReads, 0);
  }

  // ── Core: check conditions and show prompt ─────────────────────────────────
  Future<void> _maybeShowPrompt(BuildContext context, {required String trigger}) async {
    if (!Platform.isAndroid) return; // Android only

    final prefs = await SharedPreferences.getInstance();

    // 1. Never show if already rated
    if (prefs.getBool(_kHasRated) == true) return;

    // 2. Respect 30-day cooldown
    final lastShownStr = prefs.getString(_kLastShownDate);
    if (lastShownStr != null) {
      final lastShown = DateTime.tryParse(lastShownStr);
      if (lastShown != null) {
        final daysSince = DateTime.now().difference(lastShown).inDays;
        if (daysSince < _minDaysBetweenPrompts) return;
      }
    }

    // Record the time we're showing the prompt
    await prefs.setString(_kLastShownDate, DateTime.now().toIso8601String());

    // Try native Play Store in-app review
    final available = await _inAppReview.isAvailable();
    if (available) {
      try {
        await _inAppReview.requestReview();
        // We can't know if user actually rated (Play Store API limitation)
        // But we record the prompt was shown
        return;
      } catch (_) {}
    }

    // Fallback: custom dialog with Play Store deep link
    if (context.mounted) {
      _showFallbackDialog(context, prefs);
    }
  }

  // ── Fallback rating dialog ─────────────────────────────────────────────────
  void _showFallbackDialog(BuildContext context, SharedPreferences prefs) {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: const Column(
          children: [
            Text('⭐', style: TextStyle(fontSize: 40)),
            SizedBox(height: 8),
            Text(
              'Enjoying NewsXpressLive?',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
          ],
        ),
        content: const Text(
          'Your 5-star rating helps more journalists discover our platform.\nIt takes just 10 seconds! 🙏',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 14, height: 1.5),
        ),
        actionsAlignment: MainAxisAlignment.center,
        actions: [
          TextButton(
            onPressed: () {
              prefs.setString(_kLastShownDate, DateTime.now().toIso8601String());
              Navigator.of(context).pop();
            },
            child: const Text(
              'Maybe Later',
              style: TextStyle(color: Colors.grey),
            ),
          ),
          const SizedBox(width: 8),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF0D47A1),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10)),
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
            ),
            onPressed: () async {
              Navigator.of(context).pop();
              await prefs.setBool(_kHasRated, true);
              const playStoreUrl =
                  'https://play.google.com/store/apps/details?id=com.newsxpresslive';
              final uri = Uri.parse(playStoreUrl);
              if (await canLaunchUrl(uri)) {
                await launchUrl(uri,
                    mode: LaunchMode.externalApplication);
              }
            },
            child: const Text(
              '⭐ Rate Us',
              style: TextStyle(fontWeight: FontWeight.bold),
            ),
          ),
        ],
      ),
    );
  }
}
