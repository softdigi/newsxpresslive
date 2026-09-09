import 'package:flutter/material.dart';
import '../../../core/constants/app_colors.dart';

/// AR News Experience — Beta feature (premium users only).
///
/// Foundation screen that can be extended with ARCore/ARKit integration.
/// Currently shows a placeholder with camera view simulation.
class ArNewsScreen extends StatelessWidget {
  const ArNewsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: const Row(
          children: [
            Text('AR News', style: TextStyle(color: Colors.white)),
            SizedBox(width: 8),
            _BetaBadge(),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.info_outline, color: Colors.white70),
            onPressed: () => _showInfo(context),
          ),
        ],
      ),
      body: const _ArPlaceholder(),
    );
  }

  void _showInfo(BuildContext context) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('AR News (Beta)'),
        content: const Text(
          'अपने camera को किसी location की तरफ point करें — '
          'वहाँ की related news AR overlay में दिखाई देगी।\n\n'
          'यह feature beta में है। आपके feedback का स्वागत है।',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('OK'),
          ),
        ],
      ),
    );
  }
}

class _BetaBadge extends StatelessWidget {
  const _BetaBadge();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color: AppColors.accent,
        borderRadius: BorderRadius.circular(4),
      ),
      child: const Text(
        'BETA',
        style: TextStyle(
            color: Colors.black,
            fontSize: 9,
            fontWeight: FontWeight.bold),
      ),
    );
  }
}

class _ArPlaceholder extends StatelessWidget {
  const _ArPlaceholder();

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        // Camera-like dark background
        Container(
          decoration: const BoxDecoration(
            gradient: RadialGradient(
              center: Alignment.center,
              radius: 0.9,
              colors: [Color(0xFF1A1A2E), Colors.black],
            ),
          ),
        ),

        // Grid overlay (camera reticle style)
        CustomPaint(
          painter: _GridPainter(),
          size: Size.infinite,
        ),

        // Sample AR news pins
        const _ArNewsPinOverlay(),

        // Bottom instruction card
        Positioned(
          bottom: 24,
          left: 16,
          right: 16,
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.black.withAlpha(180),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.white24),
            ),
            child: const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.camera_alt_outlined,
                        color: Colors.white70, size: 18),
                    SizedBox(width: 8),
                    Text(
                      'Camera Permission Required',
                      style: TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.bold,
                          fontSize: 13),
                    ),
                  ],
                ),
                SizedBox(height: 6),
                Text(
                  'Camera और Location access allow करें to see AR news overlays in your surroundings.',
                  style: TextStyle(color: Colors.white60, fontSize: 12),
                ),
              ],
            ),
          ),
        ),

        // Coming soon overlay
        Center(
          child: Container(
            padding:
                const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
            decoration: BoxDecoration(
              color: Colors.black.withAlpha(160),
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.view_in_ar_rounded,
                    size: 52, color: AppColors.accent),
                SizedBox(height: 12),
                Text(
                  'AR News coming soon',
                  style: TextStyle(
                      color: Colors.white,
                      fontSize: 18,
                      fontWeight: FontWeight.bold),
                ),
                SizedBox(height: 6),
                Text(
                  'Location-based news overlay\nwith ARCore / ARKit',
                  style: TextStyle(color: Colors.white60, fontSize: 13),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _ArNewsPinOverlay extends StatelessWidget {
  const _ArNewsPinOverlay();

  @override
  Widget build(BuildContext context) {
    // Simulated AR pins — replace with real AR markers when ar_flutter_plugin
    // or similar is integrated.
    return Stack(
      children: const [
        Positioned(top: 120, left: 60,  child: _ArPin(label: 'Local News')),
        Positioned(top: 200, right: 80, child: _ArPin(label: 'Event nearby')),
        Positioned(top: 310, left: 140, child: _ArPin(label: 'Breaking')),
      ],
    );
  }
}

class _ArPin extends StatelessWidget {
  final String label;
  const _ArPin({required this.label});

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: 0.55,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: AppColors.primary.withAlpha(200),
          borderRadius: BorderRadius.circular(8),
          boxShadow: [
            BoxShadow(color: Colors.black.withAlpha(60), blurRadius: 6),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.article_outlined,
                color: Colors.white, size: 14),
            const SizedBox(width: 4),
            Text(label,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 11,
                    fontWeight: FontWeight.bold)),
          ],
        ),
      ),
    );
  }
}

class _GridPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = Colors.white.withAlpha(12)
      ..strokeWidth = 0.5;
    const step = 40.0;
    for (double x = 0; x < size.width; x += step) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), paint);
    }
    for (double y = 0; y < size.height; y += step) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), paint);
    }
    // Center reticle
    final cx = size.width / 2;
    final cy = size.height / 2;
    final rPaint = Paint()
      ..color = Colors.white.withAlpha(80)
      ..strokeWidth = 1.5
      ..style = PaintingStyle.stroke;
    canvas.drawCircle(Offset(cx, cy), 40, rPaint);
    canvas.drawCircle(Offset(cx, cy), 8, rPaint..color = AppColors.primary.withAlpha(180));
  }

  @override
  bool shouldRepaint(_GridPainter old) => false;
}
