import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import '../../../core/constants/app_colors.dart';
import '../../../providers/auth_provider.dart';

// ── Model ─────────────────────────────────────────────────────────────────────

class HoroscopeData {
  final String sign;
  final String signHi;
  final String symbol;
  final String date;
  final String content;
  final int? luckyNumber;
  final String? luckyColor;
  final String? luckyTime;
  final int? generalScore;
  final int? loveScore;
  final int? careerScore;
  final int? healthScore;

  const HoroscopeData({
    required this.sign,
    required this.signHi,
    required this.symbol,
    required this.date,
    required this.content,
    this.luckyNumber,
    this.luckyColor,
    this.luckyTime,
    this.generalScore,
    this.loveScore,
    this.careerScore,
    this.healthScore,
  });

  factory HoroscopeData.fromJson(Map<String, dynamic> j) => HoroscopeData(
        sign: j['sign'] ?? '',
        signHi: j['sign_hi'] ?? '',
        symbol: j['symbol'] ?? '',
        date: j['date'] ?? '',
        content: j['content'] ?? '',
        luckyNumber: j['lucky_number'] as int?,
        luckyColor: j['lucky_color'] as String?,
        luckyTime: j['lucky_time'] as String?,
        generalScore: (j['scores']?['general']) as int?,
        loveScore: (j['scores']?['love']) as int?,
        careerScore: (j['scores']?['career']) as int?,
        healthScore: (j['scores']?['health']) as int?,
      );
}

// ── Zodiac sign metadata ──────────────────────────────────────────────────────

const _zodiacSigns = [
  {'en': 'aries',       'hi': 'मेष',      'symbol': '♈', 'emoji': '🐏'},
  {'en': 'taurus',      'hi': 'वृषभ',     'symbol': '♉', 'emoji': '🐂'},
  {'en': 'gemini',      'hi': 'मिथुन',    'symbol': '♊', 'emoji': '👫'},
  {'en': 'cancer',      'hi': 'कर्क',     'symbol': '♋', 'emoji': '🦀'},
  {'en': 'leo',         'hi': 'सिंह',     'symbol': '♌', 'emoji': '🦁'},
  {'en': 'virgo',       'hi': 'कन्या',    'symbol': '♍', 'emoji': '👧'},
  {'en': 'libra',       'hi': 'तुला',     'symbol': '♎', 'emoji': '⚖️'},
  {'en': 'scorpio',     'hi': 'वृश्चिक',  'symbol': '♏', 'emoji': '🦂'},
  {'en': 'sagittarius', 'hi': 'धनु',      'symbol': '♐', 'emoji': '🏹'},
  {'en': 'capricorn',   'hi': 'मकर',      'symbol': '♑', 'emoji': '🐐'},
  {'en': 'aquarius',    'hi': 'कुम्भ',    'symbol': '♒', 'emoji': '🏺'},
  {'en': 'pisces',      'hi': 'मीन',      'symbol': '♓', 'emoji': '🐟'},
];

// ── Main Screen ───────────────────────────────────────────────────────────────

class HoroscopeScreen extends StatefulWidget {
  final String? initialSign;
  const HoroscopeScreen({super.key, this.initialSign});

  @override
  State<HoroscopeScreen> createState() => _HoroscopeScreenState();
}

class _HoroscopeScreenState extends State<HoroscopeScreen>
    with SingleTickerProviderStateMixin {
  late String _selectedSign;
  HoroscopeData? _data;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _selectedSign = widget.initialSign ?? 'aries';
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final uri = Uri.parse(
          'https://newsxpresslive.com/web/api/horoscope/today.php'
          '?sign=$_selectedSign&lang=hi');
      final res = await http.get(uri).timeout(const Duration(seconds: 15));
      final json = jsonDecode(res.body) as Map<String, dynamic>;
      if (json['success'] == true) {
        setState(() => _data = HoroscopeData.fromJson(json));
      } else {
        setState(() => _error = json['error'] ?? 'Horoscope unavailable');
      }
    } catch (e) {
      setState(() => _error = 'Network error. Please try again.');
    } finally {
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(
        title: const Text('राशिफल'),
        actions: [
          if (_data != null)
            IconButton(
              icon: const Icon(Icons.share_rounded),
              onPressed: _shareHoroscope,
              tooltip: 'Share',
            ),
        ],
      ),
      body: Column(
        children: [
          _ZodiacWheelSelector(
            selected: _selectedSign,
            onSelect: (sign) {
              setState(() => _selectedSign = sign);
              _load();
            },
          ),
          Expanded(child: _buildBody(isDark)),
        ],
      ),
    );
  }

  Widget _buildBody(bool isDark) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, size: 48, color: AppColors.primary),
              const SizedBox(height: 12),
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              ElevatedButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }
    if (_data == null) return const SizedBox.shrink();
    return _HoroscopeCard(data: _data!, isDark: isDark);
  }

  void _shareHoroscope() {
    if (_data == null) return;
    final d = _data!;
    final text =
        '${d.symbol} ${d.signHi} राशिफल — ${d.date}\n\n'
        '${d.content}\n\n'
        '🍀 Lucky Number: ${d.luckyNumber ?? "-"}\n'
        '🎨 Lucky Color: ${d.luckyColor ?? "-"}\n'
        '⏰ Lucky Time: ${d.luckyTime ?? "-"}\n\n'
        '📱 NewsXpressLive पर पूरा राशिफल पढ़ें';
    Share.share(text, subject: '${d.signHi} राशिफल');
  }
}

// ── Zodiac Wheel Selector ─────────────────────────────────────────────────────

class _ZodiacWheelSelector extends StatelessWidget {
  final String selected;
  final ValueChanged<String> onSelect;
  const _ZodiacWheelSelector({required this.selected, required this.onSelect});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 100,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
        itemCount: _zodiacSigns.length,
        itemBuilder: (ctx, i) {
          final s = _zodiacSigns[i];
          final isSelected = s['en'] == selected;
          return GestureDetector(
            onTap: () => onSelect(s['en']!),
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              margin: const EdgeInsets.symmetric(horizontal: 4),
              width: 72,
              decoration: BoxDecoration(
                color: isSelected ? AppColors.primary : Colors.transparent,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: isSelected ? AppColors.primary : Colors.grey.shade400,
                ),
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(s['emoji']!, style: const TextStyle(fontSize: 22)),
                  const SizedBox(height: 2),
                  Text(
                    s['hi']!,
                    style: TextStyle(
                      fontSize: 10,
                      color: isSelected ? Colors.white : null,
                      fontWeight:
                          isSelected ? FontWeight.bold : FontWeight.normal,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

// ── Horoscope Content Card ────────────────────────────────────────────────────

class _HoroscopeCard extends StatelessWidget {
  final HoroscopeData data;
  final bool isDark;
  const _HoroscopeCard({required this.data, required this.isDark});

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Sign header
          Row(
            children: [
              Text(data.symbol, style: const TextStyle(fontSize: 48)),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(data.signHi,
                      style: const TextStyle(
                          fontSize: 22, fontWeight: FontWeight.bold)),
                  Text(data.date,
                      style: TextStyle(color: Colors.grey.shade500)),
                ],
              ),
            ],
          ),
          const SizedBox(height: 16),

          // Score bars
          _ScoreBars(
            general: data.generalScore,
            love: data.loveScore,
            career: data.careerScore,
            health: data.healthScore,
          ),
          const SizedBox(height: 16),

          // Lucky items row
          Row(
            children: [
              _LuckyItem(icon: '🔢', label: '${data.luckyNumber ?? "-"}',
                  title: 'अंक'),
              _LuckyItem(icon: '🎨', label: data.luckyColor ?? '-',
                  title: 'रंग'),
              _LuckyItem(icon: '⏰', label: data.luckyTime ?? '-',
                  title: 'समय'),
            ],
          ),
          const SizedBox(height: 16),

          // Prediction text
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: isDark ? AppColors.cardDark : AppColors.cardLight,
              borderRadius: BorderRadius.circular(12),
              boxShadow: [
                BoxShadow(color: Colors.black.withAlpha(18),
                    blurRadius: 8, offset: const Offset(0, 2)),
              ],
            ),
            child: Text(
              data.content,
              style: const TextStyle(fontSize: 16, height: 1.6),
            ),
          ),
          const SizedBox(height: 16),

          // Tomorrow's preview — locked for free users
          _TomorrowPreview(),
        ],
      ),
    );
  }
}

class _LuckyItem extends StatelessWidget {
  final String icon;
  final String label;
  final String title;
  const _LuckyItem(
      {required this.icon, required this.label, required this.title});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Card(
        margin: const EdgeInsets.symmetric(horizontal: 4),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
          child: Column(
            children: [
              Text(icon, style: const TextStyle(fontSize: 20)),
              const SizedBox(height: 4),
              Text(title,
                  style: const TextStyle(fontSize: 10, color: Colors.grey)),
              const SizedBox(height: 2),
              Text(label,
                  style: const TextStyle(
                      fontSize: 13, fontWeight: FontWeight.bold),
                  textAlign: TextAlign.center,
                  overflow: TextOverflow.ellipsis),
            ],
          ),
        ),
      ),
    );
  }
}

class _ScoreBars extends StatelessWidget {
  final int? general, love, career, health;
  const _ScoreBars(
      {this.general, this.love, this.career, this.health});

  @override
  Widget build(BuildContext context) {
    final items = [
      ('सामान्य', general, AppColors.accent),
      ('प्रेम',   love,    AppColors.primary),
      ('करियर',  career,  const Color(0xFF4A90D9)),
      ('स्वास्थ्य', health, const Color(0xFF1D9E75)),
    ];
    return Column(
      children: items.map((t) {
        final val = t.$2 ?? 0;
        return Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(
            children: [
              SizedBox(
                width: 70,
                child: Text(t.$1,
                    style: const TextStyle(fontSize: 12)),
              ),
              Expanded(
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: val / 10,
                    minHeight: 8,
                    backgroundColor: Colors.grey.shade200,
                    valueColor: AlwaysStoppedAnimation<Color>(t.$3),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Text('$val/10',
                  style: const TextStyle(fontSize: 11, color: Colors.grey)),
            ],
          ),
        );
      }).toList(),
    );
  }
}

class _TomorrowPreview extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.primary.withAlpha(18),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.primary.withAlpha(60)),
      ),
      child: Row(
        children: [
          const Icon(Icons.lock_rounded, color: AppColors.primary),
          const SizedBox(width: 12),
          const Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('कल का राशिफल',
                    style: TextStyle(fontWeight: FontWeight.bold)),
                Text('Premium सदस्यता में उपलब्ध',
                    style: TextStyle(fontSize: 12, color: Colors.grey)),
              ],
            ),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: Colors.white),
            onPressed: () {},
            child: const Text('Upgrade'),
          ),
        ],
      ),
    );
  }
}

// ── Small HomeScreen card widget ──────────────────────────────────────────────

class RashifalHomeCard extends StatelessWidget {
  final String? savedSign;
  const RashifalHomeCard({super.key, this.savedSign});

  @override
  Widget build(BuildContext context) {
    final sign = savedSign ?? 'aries';
    final meta = _zodiacSigns.firstWhere(
        (s) => s['en'] == sign,
        orElse: () => _zodiacSigns.first);

    return GestureDetector(
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute(
            builder: (_) => HoroscopeScreen(initialSign: sign)),
      ),
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
              colors: [Color(0xFF6A0DAD), Color(0xFF9B59B6)]),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          children: [
            Text(meta['emoji']!, style: const TextStyle(fontSize: 32)),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'आज का राशिफल — ${meta['hi']}',
                    style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        fontSize: 15),
                  ),
                  const Text(
                    'अपना दैनिक राशिफल देखें',
                    style: TextStyle(color: Colors.white70, fontSize: 12),
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_right, color: Colors.white),
          ],
        ),
      ),
    );
  }
}
