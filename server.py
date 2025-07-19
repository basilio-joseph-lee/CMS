from flask import Flask, request, jsonify
import face_recognition
import numpy as np
import base64
import cv2
import mysql.connector
import os

app = Flask(__name__)
BASE_IMAGE_DIR = "C:/xampp/htdocs/CMS/config"

def load_faces_by_enrollment(subject_id, advisory_id, school_year_id):
    known_encodings = []
    known_names = []
    known_ids = []

    try:
        conn = mysql.connector.connect(
            host='localhost',
            user='root',
            password='',
            database='cms'
        )
        cursor = conn.cursor()

        query = """
        SELECT s.student_id, s.fullname, s.face_image_path 
        FROM students s
        JOIN student_enrollments e ON s.student_id = e.student_id
        WHERE e.subject_id = %s AND e.advisory_id = %s AND e.school_year_id = %s
        """
        cursor.execute(query, (subject_id, advisory_id, school_year_id))
        results = cursor.fetchall()

        for student_id, name, rel_path in results:
            full_path = os.path.join(BASE_IMAGE_DIR, rel_path.replace("\\", "/"))
            if not os.path.exists(full_path):
                print(f"❌ File not found: {full_path}")
                continue

            img = cv2.imread(full_path)
            if img is None:
                print(f"❌ Could not read image: {full_path}")
                continue

            encodings = face_recognition.face_encodings(img)
            if encodings:
                known_encodings.append(encodings[0])
                known_names.append(name)
                known_ids.append(student_id)
                print(f"✅ Loaded face for: {name}")
            else:
                print(f"⚠️ No face found in: {full_path}")

        cursor.close()
        conn.close()

    except Exception as e:
        print("❌ DB error:", str(e))

    return known_encodings, known_names, known_ids

@app.route('/verify', methods=['POST'])
def verify_face():
    data = request.get_json()
    if 'image' not in data or not all(k in data for k in ['subject_id', 'advisory_id', 'school_year_id']):
        return jsonify({'match': False, 'error': 'Missing required data (image, subject_id, advisory_id, school_year_id)'})

    try:
        image_data = data['image'].split(',')[1]
        decoded = base64.b64decode(image_data)
        np_array = np.frombuffer(decoded, np.uint8)
        frame = cv2.imdecode(np_array, cv2.IMREAD_COLOR)

        unknown_encodings = face_recognition.face_encodings(frame)
        if not unknown_encodings:
            return jsonify({'match': False, 'error': 'No face found in input image'})

        known_encodings, known_names, known_ids = load_faces_by_enrollment(
            data['subject_id'], data['advisory_id'], data['school_year_id']
        )

        threshold = 0.5
        best_match_name = None
        best_match_id = None
        best_distance = float('inf')

        for known_encoding, name, sid in zip(known_encodings, known_names, known_ids):
            distance = face_recognition.face_distance([known_encoding], unknown_encodings[0])[0]
            if distance < threshold and distance < best_distance:
                best_distance = distance
                best_match_name = name
                best_match_id = sid

        if best_match_name:
            confidence = round((1 - best_distance) * 100, 2)
            return jsonify({
                'match': True,
                'name': best_match_name,
                'student_id': best_match_id,
                'confidence': confidence
            })
        else:
            return jsonify({'match': False, 'error': 'No match found'})

    except Exception as e:
        return jsonify({'match': False, 'error': str(e)})

if __name__ == '__main__':
    app.run(debug=True)
