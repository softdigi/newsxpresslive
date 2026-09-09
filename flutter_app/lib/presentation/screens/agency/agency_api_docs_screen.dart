import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../core/constants/app_colors.dart';
import '../../../providers/agency_provider.dart';

class AgencyApiDocsScreen extends StatefulWidget {
  const AgencyApiDocsScreen({super.key});

  @override
  State<AgencyApiDocsScreen> createState() => _AgencyApiDocsScreenState();
}

class _AgencyApiDocsScreenState extends State<AgencyApiDocsScreen> {
  bool _showKey = false;
  bool _showSecret = false;
  String _apiKey = '';
  String _apiSecret = '';
  bool _loadingKeys = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadKeys());
  }

  Future<void> _loadKeys() async {
    setState(() => _loadingKeys = true);
    try {
      final provider = context.read<AgencyProvider>();
      final result = await provider.getApiKeys();
      setState(() {
        _apiKey = result['apiKey'] as String? ?? '';
        _apiSecret = result['apiSecret'] as String? ?? '';
      });
    } catch (_) {
      final profile = context.read<AgencyProvider>().agencyProfile;
      if (profile != null) {
        setState(() {
          _apiKey = profile.apiKey;
          _apiSecret = profile.apiSecret;
        });
      }
    } finally {
      if (mounted) setState(() => _loadingKeys = false);
    }
  }

  Future<void> _rotateKeys() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Rotate API Keys'),
        content: const Text(
            'This will invalidate your current keys and generate new ones. '
            'All existing integrations will stop working until updated. Continue?'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel')),
          ElevatedButton(
            style:
                ElevatedButton.styleFrom(backgroundColor: AppColors.primary),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Rotate',
                style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    final result = await context.read<AgencyProvider>().rotateApiKeys();
    if (!mounted) return;
    if (result != null) {
      setState(() {
        _apiKey = result['apiKey'] as String? ?? '';
        _apiSecret = result['apiSecret'] as String? ?? '';
        _showKey = false;
        _showSecret = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('API keys rotated successfully!'),
            backgroundColor: Colors.green),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(
                context.read<AgencyProvider>().errorMsg ?? 'Rotation failed'),
            backgroundColor: AppColors.primary),
      );
    }
  }

  String _mask(String s) {
    if (s.length <= 4) return '****';
    return '${'*' * (s.length - 4)}${s.substring(s.length - 4)}';
  }

  void _copy(String value, String label) {
    Clipboard.setData(ClipboardData(text: value));
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('$label copied to clipboard')),
    );
  }

  @override
  Widget build(BuildContext context) {
    final curlSnippet = 'curl -X GET \\\n'
        '  "https://newsxpresslive.com/api/v1/agency/articles.php" \\\n'
        '  -H "X-Agency-Key: $_apiKey" \\\n'
        '  -H "X-Agency-Secret: $_apiSecret"';

    final pythonSnippet = 'import requests\n\n'
        'headers = {\n'
        '    "X-Agency-Key": "$_apiKey",\n'
        '    "X-Agency-Secret": "$_apiSecret",\n'
        '}\n'
        'resp = requests.get(\n'
        '    "https://newsxpresslive.com/api/v1/agency/articles.php",\n'
        '    headers=headers,\n'
        ')\n'
        'print(resp.json())';

    final phpSnippet = '<?php\n'
        r'$ch = curl_init();' '\n'
        r'curl_setopt_array($ch, [' '\n'
        '    CURLOPT_URL => "https://newsxpresslive.com/api/v1/agency/articles.php",\n'
        '    CURLOPT_RETURNTRANSFER => true,\n'
        '    CURLOPT_HTTPHEADER => [\n'
        '        "X-Agency-Key: $_apiKey",\n'
        '        "X-Agency-Secret: $_apiSecret",\n'
        '    ],\n'
        ']);\n'
        r'$response = curl_exec($ch);' '\n'
        r'curl_close($ch);' '\n'
        r'echo $response;';

    return Scaffold(
      backgroundColor: AppColors.scaffoldLight,
      appBar: AppBar(
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        title: const Text('API Docs'),
      ),
      body: _loadingKeys
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _card(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Text('API Credentials',
                              style: TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.bold,
                                  color: AppColors.textPrimaryLight)),
                          const Spacer(),
                          Consumer<AgencyProvider>(
                            builder: (_, p, __) => TextButton.icon(
                              onPressed: p.isLoading ? null : _rotateKeys,
                              icon: const Icon(Icons.refresh, size: 16),
                              label: const Text('Rotate Keys',
                                  style: TextStyle(fontSize: 12)),
                              style: TextButton.styleFrom(
                                  foregroundColor: AppColors.primary),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      _keyRow(
                        label: 'API Key',
                        value: _showKey ? _apiKey : _mask(_apiKey),
                        revealed: _showKey,
                        onToggle: () =>
                            setState(() => _showKey = !_showKey),
                        onCopy: () => _copy(_apiKey, 'API Key'),
                      ),
                      const SizedBox(height: 10),
                      _keyRow(
                        label: 'API Secret',
                        value: _showSecret ? _apiSecret : _mask(_apiSecret),
                        revealed: _showSecret,
                        onToggle: () =>
                            setState(() => _showSecret = !_showSecret),
                        onCopy: () => _copy(_apiSecret, 'API Secret'),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                _snippetCard(title: 'cURL', code: curlSnippet),
                const SizedBox(height: 12),
                _snippetCard(title: 'Python', code: pythonSnippet),
                const SizedBox(height: 12),
                _snippetCard(title: 'PHP', code: phpSnippet),
                const SizedBox(height: 16),
                OutlinedButton.icon(
                  onPressed: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                          content: Text(
                              'Add url_launcher to pubspec to open docs.')),
                    );
                  },
                  icon: const Icon(Icons.open_in_new),
                  label: const Text('View Full API Docs'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: AppColors.primary,
                    side: const BorderSide(color: AppColors.primary),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(10)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                ),
              ],
            ),
    );
  }

  Widget _keyRow({
    required String label,
    required String value,
    required bool revealed,
    required VoidCallback onToggle,
    required VoidCallback onCopy,
  }) {
    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label,
                  style: const TextStyle(
                      fontSize: 11, color: AppColors.textSecondaryLight)),
              const SizedBox(height: 2),
              Text(value,
                  style: const TextStyle(
                      fontFamily: 'monospace',
                      fontSize: 13,
                      letterSpacing: 0.5)),
            ],
          ),
        ),
        IconButton(
          onPressed: onToggle,
          icon: Icon(
              revealed
                  ? Icons.visibility_off_outlined
                  : Icons.visibility_outlined,
              size: 18),
          color: AppColors.textSecondaryLight,
          tooltip: revealed ? 'Hide' : 'Reveal',
        ),
        IconButton(
          onPressed: onCopy,
          icon: const Icon(Icons.copy, size: 18),
          color: AppColors.primary,
          tooltip: 'Copy',
        ),
      ],
    );
  }

  Widget _snippetCard({required String title, required String code}) {
    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFF1E1E1E),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding:
                const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: Row(
              children: [
                Text(title,
                    style: const TextStyle(
                        color: Colors.white70,
                        fontSize: 13,
                        fontWeight: FontWeight.w600)),
                const Spacer(),
                InkWell(
                  onTap: () => _copy(code, '$title snippet'),
                  child: const Padding(
                    padding: EdgeInsets.all(4),
                    child: Row(
                      children: [
                        Icon(Icons.copy, size: 14, color: Colors.white54),
                        SizedBox(width: 4),
                        Text('Copy',
                            style: TextStyle(
                                color: Colors.white54, fontSize: 12)),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
          const Divider(color: Colors.white12, height: 1),
          Padding(
            padding: const EdgeInsets.all(14),
            child: SelectableText(
              code,
              style: const TextStyle(
                fontFamily: 'monospace',
                fontSize: 11.5,
                color: Color(0xFFCECECE),
                height: 1.5,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _card({required Widget child}) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.cardLight,
        borderRadius: BorderRadius.circular(12),
        boxShadow: [
          BoxShadow(
              color: Colors.black.withOpacity(0.05),
              blurRadius: 6,
              offset: const Offset(0, 2))
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: child,
    );
  }
}
