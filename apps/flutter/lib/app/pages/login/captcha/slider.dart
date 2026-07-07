// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import '../../../services/captcha_service.dart';

const _sw = 300.0, _sh = 200.0;

class SliderCaptcha extends StatefulWidget {
  final CaptchaData data;
  const SliderCaptcha({super.key, required this.data});
  @override
  State<SliderCaptcha> createState() => SliderCaptchaState();
}

class SliderCaptchaState extends State<SliderCaptcha> {
  double get answer => val;
  double val = 0;

  @override
  Widget build(BuildContext c) {
    final d = widget.data;
    final p = d.puzzleBytes;
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Text('拖动拼图片对齐缺口', style: const TextStyle(fontSize: 13)),
      const SizedBox(height: 8),
      AspectRatio(aspectRatio: _sw / _sh, child: LayoutBuilder(builder: (_, box) {
        final w = box.maxWidth, h = box.maxHeight;
        // 缺口和拼图片使用完全相同的尺寸计算
        final pw = w * d.puzzleW / _sw;
        final ph = h * d.puzzleH / _sh;
        return ClipRRect(borderRadius: BorderRadius.circular(8), child: Stack(children: [
          Image.memory(d.imageBytes, width: w, height: h, fit: BoxFit.fill),
          if (p != null)
            Positioned(
              left: (val / _sw * w).clamp(0, w - pw),
              top: d.sliderY / _sh * h,
              child: Image.memory(p, width: pw, height: ph, fit: BoxFit.fill),
            ),
        ]));
      })),
      Slider(value: val, min: 0, max: _sw, onChanged: (v) => setState(() => val = v)),
    ]);
  }
}
