import 'dart:async';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Top-level handler required by Firebase Messaging for background messages.
@pragma('vm:entry-point')
Future<void> _firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  // Background processing — no UI available here.
  // The notification is shown automatically by the system.
}

/// Manages Firebase Cloud Messaging and local notifications.
///
/// Call [init] once from [main], after Firebase is initialised.
/// Subscribe to location topics after the user completes onboarding.
class NotificationService {
  NotificationService._();

  static final NotificationService instance = NotificationService._();

  final FirebaseMessaging _fcm = FirebaseMessaging.instance;

  final FlutterLocalNotificationsPlugin _localNotifications =
      FlutterLocalNotificationsPlugin();

  static const String _channelId   = 'newsxpresslive_main';
  static const String _channelName = 'NewsXpressLive';
  static const String _channelDesc = 'Breaking news and updates';

  /// Called on article notification tap — set by main.dart to navigate.
  void Function(String slug)? onArticleTap;

  // ── Initialise ────────────────────────────────────────────────────────

  Future<void> init() async {
    try {
      // Register background handler
      FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);

      // Request permission (iOS + Android 13+)
      await _fcm.requestPermission(
        alert:       true,
        badge:       true,
        sound:       true,
        provisional: false,
      );

      // Android notification channel
      const androidChannel = AndroidNotificationChannel(
        _channelId,
        _channelName,
        description:  _channelDesc,
        importance:   Importance.max,
      );

      await _localNotifications
          .resolvePlatformSpecificImplementation<
              AndroidFlutterLocalNotificationsPlugin>()
          ?.createNotificationChannel(androidChannel);

      // Initialise local notifications plugin
      const initSettings = InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
        iOS: DarwinInitializationSettings(
          requestAlertPermission: false,
          requestBadgePermission: false,
          requestSoundPermission: false,
        ),
      );
      await _localNotifications.initialize(
        initSettings,
        onDidReceiveNotificationResponse: _onLocalNotificationTap,
      );

      // Foreground message handler
      FirebaseMessaging.onMessage.listen(_handleForegroundMessage);

      // Background → foreground tap handler
      FirebaseMessaging.onMessageOpenedApp.listen(_handleNotificationTap);

      // App-launch tap (app was terminated)
      final initial = await _fcm.getInitialMessage();
      if (initial != null) _handleNotificationTap(initial);
    } catch (e) {
      // Firebase not configured — notifications unavailable, degrade gracefully.
    }
  }

  // ── FCM token ─────────────────────────────────────────────────────────

  Future<String?> getToken() async {
    try {
      return await _fcm.getToken();
    } catch (_) {
      return null;
    }
  }

  // ── Topic subscriptions (location-based) ──────────────────────────────

  Future<void> subscribeToLocationTopics({
    required int countryId,
    int?         stateId,
    int?         districtId,
  }) async {
    try {
      await _fcm.subscribeToTopic('country_$countryId');
      if (stateId    != null) await _fcm.subscribeToTopic('state_$stateId');
      if (districtId != null) await _fcm.subscribeToTopic('district_$districtId');
      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool('fcm_subscribed', true);
    } catch (_) {}
  }

  Future<void> unsubscribeAll({
    int? countryId,
    int? stateId,
    int? districtId,
  }) async {
    try {
      if (countryId  != null) await _fcm.unsubscribeFromTopic('country_$countryId');
      if (stateId    != null) await _fcm.unsubscribeFromTopic('state_$stateId');
      if (districtId != null) await _fcm.unsubscribeFromTopic('district_$districtId');
    } catch (_) {}
  }

  // ── Handlers ──────────────────────────────────────────────────────────

  Future<void> _handleForegroundMessage(RemoteMessage message) async {
    final notification = message.notification;
    if (notification == null) return;

    await _localNotifications.show(
      notification.hashCode,
      notification.title,
      notification.body,
      NotificationDetails(
        android: AndroidNotificationDetails(
          _channelId,
          _channelName,
          channelDescription: _channelDesc,
          importance: Importance.max,
          priority:   Priority.high,
          icon:       '@mipmap/ic_launcher',
        ),
        iOS: const DarwinNotificationDetails(),
      ),
      payload: message.data['slug'] as String?,
    );
  }

  void _handleNotificationTap(RemoteMessage message) {
    final slug = message.data['slug'] as String?;
    if (slug != null && slug.isNotEmpty) {
      onArticleTap?.call(slug);
    }
  }

  void _onLocalNotificationTap(NotificationResponse response) {
    final slug = response.payload;
    if (slug != null && slug.isNotEmpty) {
      onArticleTap?.call(slug);
    }
  }
}
