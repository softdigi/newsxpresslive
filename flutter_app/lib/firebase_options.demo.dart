// ============================================================
// flutter_app/lib/firebase_options.demo.dart
//
// DEMO / TEMPLATE FILE — yahan sirf placeholder values hain.
// Ye file SAFE hai commit karne ke liye.
//
// REAL FILE KAISE BANAYE:
//   1. FlutterFire CLI install karo:
//      dart pub global activate flutterfire_cli
//
//   2. Project root (flutter_app/) mein run karo:
//      flutterfire configure --project=your-firebase-project-id
//
//   Ye automatically banayega:
//      lib/firebase_options.dart       ← yahi actual file hogi
//      android/app/google-services.json
//      ios/Runner/GoogleService-Info.plist
//
// IMPORTANT: Real firebase_options.dart ko commit karna SAFE hai
// (Google project ID, App ID public info hai — koi secret nahi).
// Lekin google-services.json aur GoogleService-Info.plist me
// koi secret nahi hota, phir bhi .gitignore mein dalna better practice hai.
// ============================================================

// ignore_for_file: type=lint

import 'package:firebase_core/firebase_core.dart' show FirebaseOptions;
import 'package:flutter/foundation.dart'
    show defaultTargetPlatform, kIsWeb, TargetPlatform;

/// Default [FirebaseOptions] for use with your Firebase apps.
///
/// Example:
/// ```dart
/// import 'firebase_options.dart';
/// // ...
/// await Firebase.initializeApp(
///   options: DefaultFirebaseOptions.currentPlatform,
/// );
/// ```
class DefaultFirebaseOptions {
  static FirebaseOptions get currentPlatform {
    if (kIsWeb) {
      return web;
    }
    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        return android;
      case TargetPlatform.iOS:
        return ios;
      case TargetPlatform.macOS:
        throw UnsupportedError(
          'DefaultFirebaseOptions have not been configured for macos - '
          'you can reconfigure this by running the FlutterFire CLI again.',
        );
      default:
        throw UnsupportedError(
          'DefaultFirebaseOptions are not supported for this platform.',
        );
    }
  }

  // ── REPLACE all YOUR_... values with real ones from FlutterFire CLI ───

  static const FirebaseOptions web = FirebaseOptions(
    apiKey:            'YOUR_WEB_API_KEY',
    appId:             '1:YOUR_PROJECT_NUMBER:web:YOUR_WEB_APP_ID',
    messagingSenderId: 'YOUR_PROJECT_NUMBER',
    projectId:         'your-firebase-project-id',
    authDomain:        'your-firebase-project-id.firebaseapp.com',
    databaseURL:       'https://your-firebase-project-id-default-rtdb.firebaseio.com',
    storageBucket:     'your-firebase-project-id.appspot.com',
    measurementId:     'G-XXXXXXXXXX',
  );

  static const FirebaseOptions android = FirebaseOptions(
    apiKey:            'YOUR_ANDROID_API_KEY',
    appId:             '1:YOUR_PROJECT_NUMBER:android:YOUR_ANDROID_APP_ID',
    messagingSenderId: 'YOUR_PROJECT_NUMBER',
    projectId:         'your-firebase-project-id',
    databaseURL:       'https://your-firebase-project-id-default-rtdb.firebaseio.com',
    storageBucket:     'your-firebase-project-id.appspot.com',
  );

  static const FirebaseOptions ios = FirebaseOptions(
    apiKey:            'YOUR_IOS_API_KEY',
    appId:             '1:YOUR_PROJECT_NUMBER:ios:YOUR_IOS_APP_ID',
    messagingSenderId: 'YOUR_PROJECT_NUMBER',
    projectId:         'your-firebase-project-id',
    databaseURL:       'https://your-firebase-project-id-default-rtdb.firebaseio.com',
    storageBucket:     'your-firebase-project-id.appspot.com',
    iosBundleId:       'com.yourcompany.newsxpresslive',
    iosClientId:       'YOUR_IOS_CLIENT_ID.apps.googleusercontent.com',
  );
}
