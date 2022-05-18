package com.example.intentgit

import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import android.view.View
import android.widget.Button
import android.widget.TextView


class MainActivity : AppCompatActivity() {



    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        val button: Button = findViewById(R.id.boton_principal)
        val button2: Button = findViewById(R.id.buton_secundario)
        val text1 : TextView = findViewById(R.id.text1)
        val text2 : TextView = findViewById(R.id.text2)

        button.setOnClickListener{
            text1.visibility=View.GONE
        }

        button2.setOnClickListener {
            text1.visibility = View.VISIBLE
        }

    }
}
