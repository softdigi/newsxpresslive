import 'package:flutter/foundation.dart';
import 'package:firebase_auth/firebase_auth.dart';
import '../data/models/user_model.dart';
import '../data/services/auth_service.dart';
import '../data/services/analytics_service.dart';
import '../data/services/feature_flags_service.dart';
import '../data/services/notification_service.dart';

enum AuthState { unknown, signedIn, signedOut }

/// Manages Firebase Authentication state and the app-level user.
///
/// Wrap with [ChangeNotifierProvider] in [app.dart].
class AuthProvider extends ChangeNotifier {
  AuthProvider() {
    _init();
  }

  final AuthService _auth = AuthService.instance;

  AuthState  _state    = AuthState.unknown;
  UserModel? _user;
  String?    _errorMsg;
  bool       _loading  = false;

  AuthState  get state    => _state;
  UserModel? get user     => _user;
  String?    get errorMsg => _errorMsg;
  bool       get loading  => _loading;
  bool       get isGuest  => _user == null || (_auth.isAnonymous);

  // ── Init ──────────────────────────────────────────────────────────────

  Future<void> _init() async {
    try {
      // Try to restore from cache first for instant UI
      final cached = await _auth.loadCachedUser();
      if (cached != null && _auth.isSignedIn) {
        _user  = cached;
        _state = AuthState.signedIn;
        notifyListeners();
        await _postLoginSetup(cached);
        return;
      }
      // Check live Firebase auth state
      final fbUser = _auth.firebaseUser;
      if (fbUser != null) {
        _state = AuthState.signedIn;
        _user  = UserModel(
          firebaseUid: fbUser.uid,
          name:        fbUser.displayName,
          email:       fbUser.email,
          photoUrl:    fbUser.photoURL,
        );
        notifyListeners();
        return;
      }
    } catch (_) {}

    _state = AuthState.signedOut;
    notifyListeners();
  }

  // ── Google Sign-In ────────────────────────────────────────────────────

  /// Sign in with Google.
  ///
  /// [silent] — when true, attempts a silent (cached credentials) sign-in
  /// first (used after biometric authentication).  Falls back to the
  /// interactive flow if no cached credentials are available.
  Future<bool> signInWithGoogle({bool silent = false}) async {
    _loading  = true;
    _errorMsg = null;
    notifyListeners();
    try {
      final user = await (silent
          ? _auth.signInWithGoogleSilent()
          : _auth.signInWithGoogle());
      if (user != null) {
        _user  = user;
        _state = AuthState.signedIn;
        await _postLoginSetup(user);
        await AnalyticsService.instance.logLogin(silent ? 'biometric' : 'google');
        notifyListeners();
        return true;
      }
      if (silent) {
        // Silent failed — fall back to interactive
        return signInWithGoogle(silent: false);
      }
    } on FirebaseAuthException catch (e) {
      _errorMsg = e.message ?? 'Google sign-in failed';
    } catch (_) {
      _errorMsg = 'Sign-in failed. Please try again.';
    }
    _loading = false;
    notifyListeners();
    return false;
  }

  // ── Anonymous Sign-In ─────────────────────────────────────────────────

  Future<bool> signInAnonymously() async {
    _loading  = true;
    _errorMsg = null;
    notifyListeners();
    try {
      final user = await _auth.signInAnonymously();
      if (user != null) {
        _user  = user;
        _state = AuthState.signedIn;
        await AnalyticsService.instance.logLogin('anonymous');
        notifyListeners();
        _loading = false;
        return true;
      }
    } catch (_) {
      // Anonymous fallback failed — still allow app use without auth
      _state = AuthState.signedOut;
    }
    _loading = false;
    notifyListeners();
    return false;
  }

  // ── Sign Out ──────────────────────────────────────────────────────────

  Future<void> signOut() async {
    await _auth.signOut();
    await AnalyticsService.instance.setUserId(null);
    _user     = null;
    _state    = AuthState.signedOut;
    notifyListeners();
  }

  // ── Post-login side effects ───────────────────────────────────────────

  Future<void> _postLoginSetup(UserModel user) async {
    _loading = false;
    // Fetch feature flags using backend user ID
    if (user.backendId != null) {
      await FeatureFlagsService.instance.fetch(user.backendId!);
    }
    // Set analytics user ID
    await AnalyticsService.instance.setUserId(user.firebaseUid);
  }

  // ── Firebase ID token for API headers ─────────────────────────────────

  Future<String?> getIdToken() => _auth.getIdToken();
}
