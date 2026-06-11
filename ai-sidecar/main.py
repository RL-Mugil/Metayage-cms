import os
import psycopg2
from fastapi import FastAPI, Header, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Dict, Any, Optional
import google.generativeai as genai
from groq import Groq
from dotenv import load_dotenv

load_dotenv()

app = FastAPI(title="IPFlow AI Sidecar", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Initialize AI Providers
genai_key = os.getenv("GEMINI_API_KEY")
if genai_key and genai_key != "your_gemini_key_here":
    genai.configure(api_key=genai_key)
    gemini_model = genai.GenerativeModel('gemini-1.5-flash')
else:
    gemini_model = None

groq_key = os.getenv("GROQ_API_KEY")
if groq_key and groq_key != "your_groq_key_here":
    groq_client = Groq(api_key=groq_key)
else:
    groq_client = None

# Database Connection Helper
def get_db_connection():
    try:
        conn = psycopg2.connect(
            host=os.getenv("DB_HOST", "127.0.0.1"),
            port=os.getenv("DB_PORT", "5432"),
            database=os.getenv("DB_NAME", "ipflow"),
            user=os.getenv("DB_USER", "postgres"),
            password=os.getenv("DB_PASS", "password")
        )
        return conn
    except Exception as e:
        print("Database connection error:", e)
        return None

class QueryRequest(BaseModel):
    query: str
    context: Optional[Dict[str, Any]] = None

# System prompt outlining the database schema for the SQL generator
DB_SCHEMA_PROMPT = """
You are an expert SQL generation assistant for IPFlow, an Intellectual Property Management System.
You are given a natural language question. Generate a VALID PostgreSQL SELECT statement to answer it.
Output ONLY the SQL code inside a code block, starting with ```sql and ending with ```. No other explanation text.

Here is the database schema:
- clients: id (bigint), client_code (varchar), company_name (varchar), entity_type (varchar), primary_jurisdiction (varchar), credit_limit (numeric), sla_tier (varchar), status (varchar)
- projects: id (bigint), project_code (varchar), project_name (varchar), client_id (bigint), project_type (varchar), assigned_partner_id (bigint), assigned_manager_id (bigint), hard_deadline (date), status (varchar), urgency (varchar)
- tasks: id (bigint), project_id (bigint), title (varchar), description (text), assignee_id (bigint), reviewer_id (bigint), priority (varchar), due_date (timestamp), estimated_hours (numeric), actual_hours (numeric), status (varchar), billable (boolean)
- users: id (bigint), name (varchar), email (varchar), role (varchar), status (varchar)
- invoices: id (bigint), invoice_code (varchar), client_id (bigint), total_amount (numeric), balance_due (numeric), status (varchar), issue_date (date), due_date (date)

Security constraints:
- User role is '{role}' and user ID is {user_id}.
- If user role is 'client', you MUST filter the query to only return records belonging to this client's company. (Join with client table or client_contacts where contact email = user email).
- If user role is 'associate', only query tasks where assignee_id = {user_id} or projects where assigned_manager_id = {user_id} or assigned_partner_id = {user_id}.

If the request is NOT a search question that requires querying database tables, reply with: "NO_SQL_REQUIRED"
"""

@app.post("/api/query")
async def handle_query(request: QueryRequest, x_user_id: str = Header(None), x_user_role: str = Header(None)):
    if not x_user_id or not x_user_role:
        raise HTTPException(status_code=401, detail="User authentication context missing.")

    user_query = request.query.strip()
    user_id = int(x_user_id) if x_user_id.isdigit() else 0
    role = x_user_role

    # Step 1: Check if we can generate SQL for this query
    sql_query = None
    if gemini_model or groq_client:
        system_prompt = DB_SCHEMA_PROMPT.format(role=role, user_id=user_id)
        llm_response = ""

        try:
            # We prefer Gemini, fallback to Groq
            if gemini_model:
                full_prompt = f"{system_prompt}\n\nUser Question: {user_query}"
                response = gemini_model.generate_content(full_prompt)
                llm_response = response.text.strip()
            elif groq_client:
                chat_completion = groq_client.chat.completions.create(
                    messages=[
                        {"role": "system", "content": system_prompt},
                        {"role": "user", "content": user_query}
                    ],
                    model="mixtral-8x7b-32768",
                )
                llm_response = chat_completion.choices[0].message.content.strip()
        except Exception as e:
            print("LLM Error:", e)

        # Parse generated SQL
        if "```sql" in llm_response:
            sql_query = llm_response.split("```sql")[1].split("```")[0].strip()
        elif "SELECT" in llm_response.upper():
            sql_query = llm_response

    # Step 2: If SQL was generated, execute it safely
    db_results = None
    db_error = None
    if sql_query:
        # Sanity check: verify it's a SELECT query
        if not sql_query.upper().startswith("SELECT"):
            sql_query = None
        else:
            conn = get_db_connection()
            if conn:
                try:
                    with conn.cursor() as cursor:
                        # Safety: Set transaction to read only
                        conn.set_session(readonly=True, autocommit=True)
                        cursor.execute(sql_query)
                        columns = [desc[0] for desc in cursor.description]
                        rows = cursor.fetchall()
                        db_results = [dict(zip(columns, row)) for row in rows]
                except Exception as e:
                    db_error = str(e)
                    print("SQL Execution Error:", e)
                finally:
                    conn.close()

    # Step 3: Generate final user response
    final_answer = ""
    try:
        context_str = f"Database Results: {db_results}\nDatabase Error: {db_error}" if sql_query else "No database query executed."
        user_prompt = f"""
You are the IPFlow AI Assistant.
Answer the user's question based on the provided database context.
Format your answer cleanly in markdown (use bullet points or tables where appropriate).
If no database results are found or if the query failed, explain that clearly and politely.

User Question: {user_query}
Context: {context_str}
"""
        if gemini_model:
            response = gemini_model.generate_content(user_prompt)
            final_answer = response.text
        elif groq_client:
            chat_completion = groq_client.chat.completions.create(
                messages=[
                    {"role": "user", "content": user_prompt}
                ],
                model="llama3-70b-8192",
            )
            final_answer = chat_completion.choices[0].message.content
        else:
            # Fallback mock answer if no API keys are configured
            final_answer = f"AI Sidecar running in mock fallback mode. Query received: '{user_query}'. Please configure GEMINI_API_KEY or GROQ_API_KEY in ai-sidecar/.env to enable live AI responses."
    except Exception as e:
        final_answer = f"Error generating answer: {e}. Raw SQL: {sql_query}. Results: {db_results}"

    return {
        "query": user_query,
        "sql_query": sql_query,
        "results": db_results,
        "response": final_answer
    }

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8001)
