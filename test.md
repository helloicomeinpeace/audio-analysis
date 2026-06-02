# **Senior Backend Engineer – Take Home Assignment**

# **Deliverables**

1. A **GitHub repo (preferred)** or ZIP file containing your Laravel project:
   - Source code
   - README.md with setup instructions
   - Tests
2. A **short README section** including:
   - Architecture overview (brief)
   - Key trade-offs/assumptions
   - What would you improve with more time

---

# **Scenario**

Build a simple **audio upload and analysis service** using **Laravel (PHP)**.

The system should allow users to upload `.mp3` files and return basic metadata and analysis.

---

# **Requirements**

## **1. MP3 Upload API**

Create an endpoint:

```
POST /api/upload
Content-Type: multipart/form-data
```

It should:

- Accept `.mp3` files
- Store the file (local storage is fine)
- Return analysis results in the response

---

## **2. Audio Duration**

Return:

- Duration of the audio file (in seconds or mm:ss format)
- A flag indicating whether the duration is an **outlier**

### Outlier rule (simple suggestion):

You may define this yourself. Document your assumption briefly.

---

## **3. Audio Quality Score (1–10)**

Return a **quality score from 1 to 10**.

This should be implemented using **simple heuristics only**.

Examples of acceptable signals:

- Bitrate
- Sample rate
- File size
- Encoding properties

No ML or advanced signal processing is required.

Document:

- How do you compute the score
- Why did you choose those signals

---

## **4. Duplicate Detection (Exact Match Only)**

Detect if the same audio file has already been uploaded.

Requirements:

- Filename differences must NOT matter
- Use **content-based hashing** or equivalent

Return:

- Whether the file is a duplicate
- Reference to the original upload if it exists

---

## **5. Tests**

Include:

- At least **1 unit test**
- At least **1 integration test** (upload flow or duplicate detection)

---

# **Submission Requirements**

## **README must include:**

- How to run the project locally
- Brief architecture explanation
- Assumptions you made
- Trade-offs
- What you would improve with more time

---

# **What We Evaluate**

We are looking for:

- Clean Laravel code structure
- API design clarity
- Practical engineering judgment
- Simplicity over over-engineering
- Proper use of storage + database
- Basic testing discipline
- Ability to make reasonable assumptions

---

# **Important Note**

This is intentionally a **small, pragmatic backend exercise**.

We care more about:

- how you structure your code
- how you make decisions
- how clearly you explain trade-offs

than about perfect audio analysis.

---

We’re excited to see your approach 🚀
