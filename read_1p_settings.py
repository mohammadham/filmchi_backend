import sqlite3

con = sqlite3.connect("/opt/1panel/db/1Panel.db")
cur = con.cursor()
rows = cur.execute("SELECT key, value FROM settings").fetchall()
interesting = ("api", "security")
for k, v in rows:
    if any(s in k.lower() for s in interesting):
        print(k, "=", v)
