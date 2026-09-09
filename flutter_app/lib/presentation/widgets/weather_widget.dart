// flutter_app/lib/presentation/widgets/weather_widget.dart
// Compact home-screen weather widget with emoji icons,
// wind/humidity, and a 3-day mini forecast.
// Fetches from /api/weather/current.php

import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import '../../../core/constants/api_endpoints.dart';

// ─────────────────────────────────────────────────────────────────────────────
// Emoji map for OWM icon codes
// ─────────────────────────────────────────────────────────────────────────────
const _iconEmoji = {
  '01': '☀️', '02': '⛅', '03': '☁️', '04': '☁️',
  '09': '🌧️', '10': '🌦️', '11': '⛈️', '13': '❄️', '50': '🌫️',
};

String _emoji(String? code) {
  if (code == null) return '🌤️';
  final prefix = code.length >= 2 ? code.substring(0, 2) : '';
  return _iconEmoji[prefix] ?? '🌤️';
}

// ─────────────────────────────────────────────────────────────────────────────
// Widget
// ─────────────────────────────────────────────────────────────────────────────
class WeatherWidget extends StatefulWidget {
  final String city;
  const WeatherWidget({super.key, this.city = 'Lucknow'});

  @override
  State<WeatherWidget> createState() => _WeatherWidgetState();
}

class _WeatherWidgetState extends State<WeatherWidget> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  bool _expanded = false;

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  Future<void> _fetch() async {
    setState(() => _loading = true);
    try {
      final uri = Uri.parse(ApiEndpoints.weatherCurrent)
          .replace(queryParameters: {'city': widget.city});
      final resp = await http.get(uri).timeout(const Duration(seconds: 10));
      final body = jsonDecode(resp.body) as Map<String, dynamic>;
      if (body['success'] == true && mounted) {
        setState(() {
          _data    = body;
          _loading = false;
        });
      } else {
        if (mounted) setState(() => _loading = false);
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const SizedBox(
        height: 60,
        child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
      );
    }
    if (_data == null) {
      return GestureDetector(
        onTap: _fetch,
        child: const Padding(
          padding: EdgeInsets.all(8),
          child: Text('🌡️ Weather unavailable — tap to retry',
              style: TextStyle(color: Colors.grey)),
        ),
      );
    }

    return GestureDetector(
      onTap: () => setState(() => _expanded = !_expanded),
      child: Card(
        color: const Color(0xFF0d47a1),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              _buildCompactRow(),
              if (_expanded) ...[
                const Divider(color: Colors.white24),
                _buildDetails(),
                const SizedBox(height: 8),
                _buildForecast(),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildCompactRow() {
    return Row(children: [
      Text(
        _emoji(_data!['icon'] as String?),
        style: const TextStyle(fontSize: 28),
      ),
      const SizedBox(width: 8),
      Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(
          '${_data!['city']}',
          style: const TextStyle(color: Colors.white70, fontSize: 12),
        ),
        Text(
          '${_data!['temp']}°C',
          style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold),
        ),
      ]),
      const Spacer(),
      Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text(
          '${_data!['condition']}',
          style: const TextStyle(color: Colors.white, fontSize: 12),
        ),
        Text(
          '${_data!['condition_hi']}',
          style: const TextStyle(color: Colors.white60, fontSize: 11),
        ),
      ]),
      const SizedBox(width: 4),
      Icon(
        _expanded ? Icons.keyboard_arrow_up : Icons.keyboard_arrow_down,
        color: Colors.white54,
        size: 18,
      ),
    ]);
  }

  Widget _buildDetails() {
    return Row(children: [
      _infoChip('💧', '${_data!['humidity']}%'),
      const SizedBox(width: 12),
      _infoChip('💨', '${_data!['wind_speed']} m/s'),
      const SizedBox(width: 12),
      _infoChip('🌡️', 'Feels ${_data!['feels_like']}°'),
    ]);
  }

  Widget _infoChip(String emoji, String label) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(emoji),
          const SizedBox(width: 3),
          Text(label, style: const TextStyle(color: Colors.white70, fontSize: 12)),
        ],
      );

  Widget _buildForecast() {
    final forecast = (_data!['forecast'] as List<dynamic>? ?? [])
        .cast<Map<String, dynamic>>();
    if (forecast.isEmpty) return const SizedBox.shrink();

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceAround,
      children: forecast.map((day) => Column(
        children: [
          Text(day['day'] ?? '', style: const TextStyle(color: Colors.white70, fontSize: 11)),
          Text(_emoji(day['icon'] as String?), style: const TextStyle(fontSize: 18)),
          Text('${day['high']}°/${day['low']}°',
              style: const TextStyle(color: Colors.white, fontSize: 11)),
        ],
      )).toList(),
    );
  }
}
