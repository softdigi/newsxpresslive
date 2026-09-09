import 'package:flutter/material.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import 'package:provider/provider.dart';
import '../../providers/feature_flags_provider.dart';
import '../../data/services/feature_flags_service.dart';

// ─── Test ad unit IDs — replace with real IDs for production ──────────────
const String _kBannerAdUnitIdAndroid =
    'ca-app-pub-3940256099942544/6300978111'; // Google test ID
const String _kBannerAdUnitIdIOS =
    'ca-app-pub-3940256099942544/2934735716'; // Google test ID

/// Displays an AdMob banner ad.
///
/// Respects the [FeatureFlag.adsEnabled] feature flag.
/// Renders nothing when:
///   - ads flag is disabled
///   - AdMob SDK fails to load
///   - [MobileAds.instance] throws (e.g. no AdMob app ID configured)
class AdBannerWidget extends StatefulWidget {
  const AdBannerWidget({super.key});

  @override
  State<AdBannerWidget> createState() => _AdBannerWidgetState();
}

class _AdBannerWidgetState extends State<AdBannerWidget> {
  BannerAd? _ad;
  bool _loaded = false;

  @override
  void initState() {
    super.initState();
    _loadAd();
  }

  void _loadAd() {
    try {
      final adUnitId = _adUnitId;
      _ad = BannerAd(
        adUnitId: adUnitId,
        request: const AdRequest(),
        size:    AdSize.banner,
        listener: BannerAdListener(
          onAdLoaded: (_) {
            if (mounted) setState(() => _loaded = true);
          },
          onAdFailedToLoad: (_, __) {
            _ad?.dispose();
            _ad = null;
          },
        ),
      )..load();
    } catch (_) {
      // AdMob not configured — silently skip.
    }
  }

  String get _adUnitId {
    // ignore: do_not_use_environment
    const isAndroid = bool.fromEnvironment('dart.vm.product') || true;
    return isAndroid
        ? _kBannerAdUnitIdAndroid
        : _kBannerAdUnitIdIOS;
  }

  @override
  void dispose() {
    _ad?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Check feature flag
    final flagsEnabled = context
        .watch<FeatureFlagsProvider>()
        .isEnabled(FeatureFlag.adsEnabled);

    if (!flagsEnabled || !_loaded || _ad == null) {
      return const SizedBox.shrink();
    }

    return Container(
      alignment: Alignment.center,
      width:     _ad!.size.width.toDouble(),
      height:    _ad!.size.height.toDouble(),
      child: AdWidget(ad: _ad!),
    );
  }
}
