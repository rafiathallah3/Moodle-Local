import os
import subprocess
import csv
import sys
import random
import string
import shutil
from faker import Faker

MOODLE_PATH = r"C:\wamp64\www\moodle"
PHP_BIN = r"C:\wamp64\bin\php\php8.2.29\php.exe"
fake = Faker()

def jalanin_command(command):
    try:
        subprocess.check_call(command, shell=True)
    except subprocess.CalledProcessError as e:
        print(f"Error executing command: {e}")

def get_random_string(length=8):
    letters = string.ascii_lowercase
    return ''.join(random.choice(letters) for i in range(length))

def buat_user(username, firstname, lastname, email, password):
    csv_file = "temp_users.csv"
    with open(csv_file, 'w', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(['username', 'firstname', 'lastname', 'email', 'password'])
        writer.writerow([username, firstname, lastname, email, password])

    print(f"\nCreating user '{username}'...")
    cmd = f'"{PHP_BIN}" "{MOODLE_PATH}\\public\\admin\\tool\\uploaduser\\cli\\uploaduser.php" --file="{os.path.abspath(csv_file)}" --delimiter=,'
    jalanin_command(cmd)

    if os.path.exists(csv_file):
        os.remove(csv_file)
    print("User creation process completed.")

def buat_kursus(shortname, fullname, category_id):
    csv_file = "temp_courses.csv"
    with open(csv_file, 'w', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(['shortname', 'fullname', 'category'])
        writer.writerow([shortname, fullname, category_id])

    print(f"\nCreating course '{shortname}'...")
    cmd = f'"{PHP_BIN}" "{MOODLE_PATH}\\public\\admin\\tool\\uploadcourse\\cli\\uploadcourse.php" --mode=createnew --updatemode=nothing --file="{os.path.abspath(csv_file)}" --delimiter=,'
    jalanin_command(cmd)

    if os.path.exists(csv_file):
        os.remove(csv_file)
    print("Course creation process completed. Try to look at the Manage courses page in My courses.")

def import_questions(course_id, xml_file):
    if not os.path.exists(xml_file):
        print(f"Error: File '{xml_file}' not found.")
        return

    print(f"\nImporting questions into Course ID {course_id}...")
    cmd = f'"{PHP_BIN}" "{MOODLE_PATH}\\admin\\cli\\import_question_bank.php" --courseid={course_id} --file="{os.path.abspath(xml_file)}"'
    jalanin_command(cmd)

    print("Question bank import process completed.")


def menu_user():
    while True:
        print("\n--- Add New User ---")
        print("1. Random Dummy Data")
        print("2. Custom Input")
        print("3. Back")
        
        choice = input("Select an option: ")
        
        if choice == '1':
            firstname = fake.first_name()
            lastname = fake.last_name()
            username = f"{firstname.lower()}_{lastname.lower()}_{get_random_string(3)}"
            email = f"{username}@example.com"
            password = "Password123!"
            
            print(f"Generated: {firstname} {lastname} ({username}) | {email}")
            buat_user(username, firstname, lastname, email, password)
        elif choice == '2':
            username = input("Username: ")
            firstname = input("First Name: ")
            lastname = input("Last Name: ")
            email = input("Email: ")
            password = input("Password: ")
            buat_user(username, firstname, lastname, email, password)
        elif choice == '3':
            break
        else:
            print("Invalid option.")

def menu_course():
    while True:
        print("\n--- Add New Course ---")
        print("1. Random Dummy Data")
        print("2. Custom Input")
        print("3. Back")
        
        choice = input("Select an option: ")
        
        if choice == '1':
            suffix = get_random_string(4)
            shortname = f"CRS_{suffix}"
            fullname = f"Course {suffix}"
            category_id = "1"
            print(f"Generated: {shortname} | {fullname}")
            buat_kursus(shortname, fullname, category_id)
        elif choice == '2':
            shortname = input("Course Shortname: ")
            fullname = input("Course Fullname: ")
            category_id = input("Category ID (default 1): ") or "1"
            buat_kursus(shortname, fullname, category_id)
        elif choice == '3':
            break
        else:
            print("Invalid option.")

def menu_qbank():
    while True:
        print("\n--- Add Question Bank ---")
        print("1. Default Questions (questions.xml)")
        print("2. Custom Input")
        print("3. Back")
        
        choice = input("Select an option: ")
        
        if choice == '1':
            course_id = input("Target Course ID: ")
            xml_file = "questions.xml"
            import_questions(course_id, xml_file)
        elif choice == '2':
            course_id = input("Target Course ID: ")
            xml_file = input("Path to Moodle XML file (default 'questions.xml'): ") or "questions.xml"
            import_questions(course_id, xml_file)
        elif choice == '3':
            break
        else:
            print("Invalid option.")

def main():
    while True:
        print("\n=== Moodle CLI Manager ===")
        print("1. Add New User")
        print("2. Add New Course")
        print("3. Add Question Bank")
        print("4. Exit")

        choice = input("Select an option: ")

        if choice == '1':
            menu_user()
        elif choice == '2':
            menu_course()
        elif choice == '3':
            menu_qbank()
        elif choice == '4':
            print("Exiting...")
            break
        else:
            print("Invalid option, please try again.")

if __name__ == "__main__":
    main()
