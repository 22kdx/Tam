import os, uuid, requests
from concurrent.futures import ThreadPoolExecutor

BASE = os.environ.get("TREASURY_URL", "https://chat.onboint.store/api/treasury/v1")
TOKEN = os.environ["TREASURY_API_TOKEN"]
HEADERS = {"X-Api-Token": TOKEN, "Content-Type": "application/json"}
PREFIX = "concurrency-test-" + uuid.uuid4().hex

def post(path, key, body):
    return requests.post(
        BASE + path,
        headers={**HEADERS, "Idempotency-Key": key},
        json=body,
        timeout=30,
    )

seed = post("/transactions", PREFIX + "-seed", {
    "type": "deposit", "account_id": 1, "amount": 100,
    "description": "AUTOMATED_CONCURRENCY_TEST"
})
assert seed.status_code == 201, seed.text

def withdraw(i):
    return post("/transactions", PREFIX + f"-withdraw-{i}", {
        "type": "withdrawal", "account_id": 1, "amount": 80,
        "description": "AUTOMATED_CONCURRENCY_TEST"
    })

with ThreadPoolExecutor(max_workers=2) as pool:
    results = list(pool.map(withdraw, [1, 2]))

codes = sorted(r.status_code for r in results)
assert codes == [201, 409], [(r.status_code, r.text) for r in results]
accounts = requests.get(BASE + "/accounts", headers=HEADERS, timeout=30).json()["data"]
balance = float(next(a["balance"] for a in accounts if a["id"] == 1))
assert balance == 20, balance
print({"ok": True, "status_codes": codes, "final_balance": balance, "prefix": PREFIX})
