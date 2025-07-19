from flask import Flask, jsonify
import cv2
import base64
import time
import threading

app = Flask(__name__)

@app.route('/blink-capture')
def blink_and_capture():
    result = {
        "success": False,
        "error": "",
        "image": ""
    }

    try:
        cap = cv2.VideoCapture(0)
        if not cap.isOpened():
            result["error"] = "Cannot access camera"
            return jsonify(result)

        blink_detected = False
        face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
        eye_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_eye_tree_eyeglasses.xml')

        start_time = time.time()
        while time.time() - start_time < 5:
            ret, frame = cap.read()
            if not ret:
                result["error"] = "Frame capture failed"
                break

            gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
            faces = face_cascade.detectMultiScale(gray, 1.3, 5)

            for (x, y, w, h) in faces:
                roi_gray = gray[y:y+h, x:x+w]
                eyes = eye_cascade.detectMultiScale(roi_gray)
                if len(eyes) < 2:  # Blinking: 0 or 1 eye detected
                    blink_detected = True
                    break

            if blink_detected:
                ret, buffer = cv2.imencode('.jpg', frame)
                img_base64 = base64.b64encode(buffer).decode('utf-8')
                result["success"] = True
                result["image"] = "data:image/jpeg;base64," + img_base64
                break

        if not blink_detected:
            result["error"] = "Blink not detected. Please try again."

        cap.release()
        return jsonify(result)

    except Exception as e:
        result["error"] = str(e)
        return jsonify(result)

if __name__ == '__main__':
    app.run(debug=True, port=5000)
